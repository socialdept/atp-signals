import { describe, it, expect, beforeEach, mock } from "bun:test";

/**
 * Unit tests for the batcher's buffering logic.
 * These test the core buffer/flush mechanics in isolation.
 */

// Buffer implementation extracted for testability
class EventBuffer {
  private buffer: unknown[] = [];
  private flushTimer: ReturnType<typeof setTimeout> | null = null;
  private flushing = false;

  constructor(
    private batchSize: number,
    private batchTimeoutMs: number,
    private onFlush: (events: unknown[]) => Promise<void>,
  ) {}

  get size(): number {
    return this.buffer.length;
  }

  push(event: unknown): void {
    this.buffer.push(event);

    if (this.buffer.length >= this.batchSize) {
      this.flush();
    } else if (this.flushTimer === null && !this.flushing) {
      this.flushTimer = setTimeout(() => {
        this.flushTimer = null;
        this.flush();
      }, this.batchTimeoutMs);
    }
  }

  async flush(): Promise<void> {
    if (this.buffer.length === 0 || this.flushing) {
      return;
    }

    this.flushing = true;

    if (this.flushTimer !== null) {
      clearTimeout(this.flushTimer);
      this.flushTimer = null;
    }

    const batch = this.buffer.splice(0);

    try {
      await this.onFlush(batch);
    } catch {
      // Re-buffer on failure
      this.buffer.unshift(...batch);
    }

    this.flushing = false;
  }
}

describe("EventBuffer", () => {
  let flushed: unknown[][];

  function createBuffer(
    batchSize = 3,
    timeoutMs = 100,
  ): EventBuffer {
    flushed = [];
    return new EventBuffer(batchSize, timeoutMs, async (events) => {
      flushed.push(events);
    });
  }

  it("flushes when batch size is reached", async () => {
    const buf = createBuffer(3);

    buf.push({ id: 1 });
    buf.push({ id: 2 });
    expect(flushed.length).toBe(0);

    buf.push({ id: 3 });
    // flush is called synchronously when batch size is hit, but onFlush is async
    await Bun.sleep(10);

    expect(flushed.length).toBe(1);
    expect(flushed[0]).toHaveLength(3);
    expect(buf.size).toBe(0);
  });

  it("flushes on timeout when batch size is not reached", async () => {
    const buf = createBuffer(100, 50);

    buf.push({ id: 1 });
    buf.push({ id: 2 });

    expect(flushed.length).toBe(0);

    await Bun.sleep(80);

    expect(flushed.length).toBe(1);
    expect(flushed[0]).toHaveLength(2);
    expect(buf.size).toBe(0);
  });

  it("does not flush an empty buffer", async () => {
    const buf = createBuffer(3);

    await buf.flush();

    expect(flushed.length).toBe(0);
  });

  it("re-buffers events on flush failure", async () => {
    flushed = [];
    let shouldFail = true;

    const buf = new EventBuffer(3, 5000, async (events) => {
      if (shouldFail) {
        throw new Error("network error");
      }
      flushed.push(events);
    });

    buf.push({ id: 1 });
    buf.push({ id: 2 });
    buf.push({ id: 3 });
    await Bun.sleep(10);

    // Events should be re-buffered
    expect(buf.size).toBe(3);
    expect(flushed.length).toBe(0);

    // Now let it succeed
    shouldFail = false;
    await buf.flush();
    await Bun.sleep(10);

    expect(buf.size).toBe(0);
    expect(flushed.length).toBe(1);
    expect(flushed[0]).toHaveLength(3);
  });

  it("flushes multiple batches correctly", async () => {
    const buf = createBuffer(2);

    buf.push({ id: 1 });
    buf.push({ id: 2 });
    await Bun.sleep(10);

    buf.push({ id: 3 });
    buf.push({ id: 4 });
    await Bun.sleep(10);

    expect(flushed.length).toBe(2);
    expect(flushed[0]).toHaveLength(2);
    expect(flushed[1]).toHaveLength(2);
    expect(buf.size).toBe(0);
  });

  it("handles manual flush of partial buffer", async () => {
    const buf = createBuffer(100);

    buf.push({ id: 1 });
    await buf.flush();

    expect(flushed.length).toBe(1);
    expect(flushed[0]).toHaveLength(1);
    expect(buf.size).toBe(0);
  });
});
