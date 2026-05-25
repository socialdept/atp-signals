/**
 * Tap Batcher Proxy
 *
 * Sits between Tap (which delivers single events via HTTP webhook) and the
 * Laravel app. Receives individual event POSTs from Tap, buffers them,
 * then forwards batches to the Laravel bulk webhook endpoint.
 *
 * Architecture:
 *   Tap --POST single event--> Batcher (Bun.serve)
 *                              Batcher buffers
 *                              Batcher --POST batch--> Laravel /webhook/bulk
 *
 * Authentication: Tap sends the same Basic admin auth (user `admin`,
 * password TAP_ADMIN_PASSWORD) it would normally send to the Laravel
 * webhook. The batcher verifies it and forwards the same credential on
 * the bulk POST so Laravel's TapBulkWebhookController auth check passes.
 *
 * Environment variables:
 *   BATCHER_HOST          - Interface to listen on (default: 127.0.0.1)
 *   BATCHER_PORT          - Port to listen on (default: 9999)
 *   BATCHER_PATH          - Path Tap POSTs to (default: /)
 *   WEBHOOK_BULK_URL      - Laravel bulk endpoint URL
 *   WEBHOOK_AUTH_PASSWORD - Basic auth password (user: admin) shared with Tap
 *   BATCH_SIZE            - Max events per batch (default: 500)
 *   BATCH_TIMEOUT_MS      - Max ms before flushing (default: 5000)
 *   BATCHER_INSECURE_TLS  - Skip TLS verification on the outbound POST.
 *                           Only set in local dev where the Laravel host
 *                           uses a self-signed cert (e.g. Herd). Default: false
 */

const HOST = process.env.BATCHER_HOST ?? "127.0.0.1";
const PORT = parseInt(process.env.BATCHER_PORT ?? "9999", 10);
const PATH = process.env.BATCHER_PATH ?? "/";
const BULK_URL = process.env.WEBHOOK_BULK_URL ?? "https://offprint.test/_atp/tap/webhook/bulk";
const AUTH_PASSWORD = process.env.WEBHOOK_AUTH_PASSWORD ?? "";
const BATCH_SIZE = parseInt(process.env.BATCH_SIZE ?? "500", 10);
const BATCH_TIMEOUT_MS = parseInt(process.env.BATCH_TIMEOUT_MS ?? "5000", 10);
const INSECURE_TLS = process.env.BATCHER_INSECURE_TLS === "true";

/**
 * Each buffered event carries a pending HTTP response. We only resolve it
 * after the batch containing the event is successfully delivered to Laravel.
 * That way Tap's outbox_buffers is the source of truth: if delivery fails,
 * we return 5xx and Tap retries the event from its durable outbox.
 */
type Pending = {
  event: unknown;
  resolve: (response: Response) => void;
};

let buffer: Pending[] = [];
let flushTimer: ReturnType<typeof setTimeout> | null = null;
let flushing = false;
let shuttingDown = false;

function log(msg: string, ...args: unknown[]) {
  console.log(`[tap-batcher] ${msg}`, ...args);
}

function warn(msg: string, ...args: unknown[]) {
  console.warn(`[tap-batcher] ${msg}`, ...args);
}

function expectedAuthHeader(): string {
  return `Basic ${btoa(`admin:${AUTH_PASSWORD}`)}`;
}

function verifyAuth(req: Request): boolean {
  if (!AUTH_PASSWORD) {
    return true; // no password configured = open
  }

  return req.headers.get("authorization") === expectedAuthHeader();
}

async function flush(): Promise<void> {
  if (buffer.length === 0 || flushing) {
    return;
  }

  flushing = true;
  clearTimer();

  const batch = buffer.splice(0);
  const events = batch.map((p) => p.event);
  log(`Flushing ${batch.length} events`);

  let attempt = 0;
  const maxAttempts = 5;

  while (attempt < maxAttempts) {
    try {
      const response = await fetch(BULK_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: expectedAuthHeader(),
        },
        body: JSON.stringify({ events }),
        // Bun-specific: disable TLS verification when targeting a local
        // self-signed host (e.g. Herd's *.test certificates).
        ...(INSECURE_TLS ? { tls: { rejectUnauthorized: false } } : {}),
      } as RequestInit);

      if (!response.ok) {
        const body = await response.text().catch(() => "");
        throw new Error(`HTTP ${response.status}: ${body}`);
      }

      const result = (await response.json()) as { processed?: number };
      log(`Batch delivered: ${result.processed ?? batch.length} processed`);
      ackBatch(batch, 202);
      flushing = false;
      return;
    } catch (err) {
      attempt++;
      const delay = Math.min(1000 * 2 ** attempt, 30000);
      warn(`Flush failed (attempt ${attempt}/${maxAttempts}): ${err}`);

      if (attempt < maxAttempts) {
        await Bun.sleep(delay);
      }
    }
  }

  // All retries exhausted. Nack the whole batch with 503 — Tap will retry
  // each event from its durable outbox_buffers with its own backoff. The
  // in-memory copy is dropped intentionally so we don't double-process when
  // Tap resends.
  warn(`All ${maxAttempts} flush attempts failed, nacking ${batch.length} events back to Tap`);
  ackBatch(batch, 503);
  flushing = false;
}

function ackBatch(batch: Pending[], status: number): void {
  const body =
    status >= 200 && status < 300
      ? Response.json({ status: "delivered" }, { status })
      : new Response("Batcher flush failed; retry me", { status });

  for (const pending of batch) {
    pending.resolve(body.clone());
  }
}

function clearTimer() {
  if (flushTimer !== null) {
    clearTimeout(flushTimer);
    flushTimer = null;
  }
}

function scheduleFlush() {
  if (flushTimer === null && !flushing) {
    flushTimer = setTimeout(() => {
      flushTimer = null;
      flush();
    }, BATCH_TIMEOUT_MS);
  }
}

function ingest(event: unknown): Promise<Response> {
  return new Promise<Response>((resolve) => {
    buffer.push({ event, resolve });

    if (buffer.length >= BATCH_SIZE) {
      flush();
    } else {
      scheduleFlush();
    }
  });
}

async function handleRequest(req: Request): Promise<Response> {
  const url = new URL(req.url);

  if (req.method === "GET" && url.pathname === "/health") {
    return Response.json({
      status: "ok",
      buffered: buffer.length,
      batch_size: BATCH_SIZE,
      batch_timeout_ms: BATCH_TIMEOUT_MS,
    });
  }

  if (req.method !== "POST" || url.pathname !== PATH) {
    return new Response("Not found", { status: 404 });
  }

  if (!verifyAuth(req)) {
    return new Response("Unauthorized", {
      status: 401,
      headers: { "WWW-Authenticate": `Basic realm="batcher"` },
    });
  }

  let body: unknown;

  try {
    body = await req.json();
  } catch {
    return new Response("Invalid JSON", { status: 400 });
  }

  // Hold the response open until the batch containing this event is either
  // delivered (2xx) or fails all retries (5xx). Tap's outbox keeps the event
  // until we 2xx, so a 5xx here means Tap retries from its durable store.
  return ingest(body);
}

async function shutdown() {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;
  log(`Shutting down, flushing ${buffer.length} pending events...`);

  // One final flush attempt; whatever doesn't deliver is nacked so Tap
  // retains it in outbox_buffers for the next batcher start.
  await flush();

  // Anything that was still pending while flush was running (e.g. arrived
  // between splice and shutdown) — nack so Tap holds them.
  if (buffer.length > 0) {
    warn(`Nacking ${buffer.length} late-arriving events`);
    ackBatch(buffer.splice(0), 503);
  }

  log("Shutdown complete");
  process.exit(0);
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);

log("Starting tap-batcher proxy");
log(`  Listen:        http://${HOST}:${PORT}${PATH}`);
log(`  Bulk URL:      ${BULK_URL}`);
log(`  Batch size:    ${BATCH_SIZE}`);
log(`  Batch timeout: ${BATCH_TIMEOUT_MS}ms`);
if (INSECURE_TLS) {
  warn("  TLS:           verification disabled (BATCHER_INSECURE_TLS=true)");
}

Bun.serve({
  hostname: HOST,
  port: PORT,
  fetch: handleRequest,
});

log(`Ready, listening on http://${HOST}:${PORT}${PATH}`);
