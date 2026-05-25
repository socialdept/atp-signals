/**
 * Tap Batcher Proxy
 *
 * Connects to Tap's WebSocket and buffers events into batches,
 * then POSTs them in bulk to Laravel's bulk webhook endpoint.
 *
 * Environment variables:
 *   TAP_WS_URL          - Tap WebSocket URL (default: ws://localhost:2480/channel)
 *   WEBHOOK_BULK_URL    - Laravel bulk endpoint URL
 *   WEBHOOK_AUTH_PASSWORD - Basic auth password (user: admin)
 *   BATCH_SIZE           - Max events per batch (default: 500)
 *   BATCH_TIMEOUT_MS     - Max ms before flushing (default: 5000)
 */

const WS_URL = process.env.TAP_WS_URL ?? "ws://localhost:2480/channel";
const BULK_URL = process.env.WEBHOOK_BULK_URL ?? "https://offprint.test/_atp/tap/webhook/bulk";
const AUTH_PASSWORD = process.env.WEBHOOK_AUTH_PASSWORD ?? "";
const BATCH_SIZE = parseInt(process.env.BATCH_SIZE ?? "500", 10);
const BATCH_TIMEOUT_MS = parseInt(process.env.BATCH_TIMEOUT_MS ?? "5000", 10);

let buffer: unknown[] = [];
let flushTimer: ReturnType<typeof setTimeout> | null = null;
let flushing = false;
let reconnectDelay = 1000;
let shuttingDown = false;

function log(msg: string, ...args: unknown[]) {
  console.log(`[tap-batcher] ${msg}`, ...args);
}

function warn(msg: string, ...args: unknown[]) {
  console.warn(`[tap-batcher] ${msg}`, ...args);
}

function authHeader(): string {
  return `Basic ${btoa(`admin:${AUTH_PASSWORD}`)}`;
}

async function flush(): Promise<void> {
  if (buffer.length === 0 || flushing) {
    return;
  }

  flushing = true;
  clearTimer();

  const batch = buffer.splice(0);
  log(`Flushing ${batch.length} events`);

  let attempt = 0;
  const maxAttempts = 5;

  while (attempt < maxAttempts) {
    try {
      const response = await fetch(BULK_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: authHeader(),
        },
        body: JSON.stringify({ events: batch }),
      });

      if (!response.ok) {
        const body = await response.text().catch(() => "");
        throw new Error(`HTTP ${response.status}: ${body}`);
      }

      const result = (await response.json()) as { processed?: number };
      log(`Batch delivered: ${result.processed ?? batch.length} processed`);
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

  // All retries exhausted — put events back at the front of the buffer
  warn(`All ${maxAttempts} flush attempts failed, re-buffering ${batch.length} events`);
  buffer.unshift(...batch);
  flushing = false;
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

function onMessage(data: string) {
  try {
    const event = JSON.parse(data);
    buffer.push(event);

    if (buffer.length >= BATCH_SIZE) {
      flush();
    } else {
      scheduleFlush();
    }
  } catch {
    warn("Failed to parse WebSocket message");
  }
}

function connect() {
  if (shuttingDown) {
    return;
  }

  log(`Connecting to ${WS_URL}`);

  const ws = new WebSocket(WS_URL);

  ws.addEventListener("open", () => {
    log("Connected");
    reconnectDelay = 1000;
  });

  ws.addEventListener("message", (event) => {
    onMessage(typeof event.data === "string" ? event.data : String(event.data));
  });

  ws.addEventListener("close", (event) => {
    log(`Connection closed: code=${event.code} reason=${event.reason}`);
    reconnect();
  });

  ws.addEventListener("error", (event) => {
    warn("WebSocket error:", event);
  });
}

function reconnect() {
  if (shuttingDown) {
    return;
  }

  log(`Reconnecting in ${reconnectDelay}ms`);
  setTimeout(() => {
    reconnectDelay = Math.min(reconnectDelay * 2, 30000);
    connect();
  }, reconnectDelay);
}

async function shutdown() {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;
  log("Shutting down, flushing remaining buffer...");
  await flush();
  log("Shutdown complete");
  process.exit(0);
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);

// Start
log("Starting tap-batcher proxy");
log(`  WS URL:        ${WS_URL}`);
log(`  Bulk URL:      ${BULK_URL}`);
log(`  Batch size:    ${BATCH_SIZE}`);
log(`  Batch timeout: ${BATCH_TIMEOUT_MS}ms`);

connect();
