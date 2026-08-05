<?php

namespace SocialDept\AtpSignals\Obelisk;

use Illuminate\Support\Facades\Log;
use SocialDept\AtpSignals\Contracts\CursorStore;

/**
 * Pull consumer for an Obelisk archive.
 *
 * Polls `social.dept.obelisk.getEvents` from a stored cursor and runs each page
 * through the same batch processor the webhook uses. Needs no inbound URL, which
 * makes it the option for local development, for hosts that cannot receive
 * webhooks, and for catching up after downtime.
 *
 * The cursor advances only after a page is processed, so a crash mid-page
 * replays that page rather than skipping it. Consumers must be idempotent —
 * they already have to be, since push delivery is at-least-once.
 */
class ObeliskConsumer
{
    protected bool $shouldStop = false;

    protected int $consecutiveFailures = 0;

    public function __construct(
        protected ObeliskClient $client,
        protected ObeliskBatchProcessor $processor,
        protected CursorStore $cursorStore,
    ) {
    }

    /**
     * Poll until stopped.
     *
     * @param  int|null  $cursor  null = resume from the stored cursor, 0 = from the
     *                           start of the archive's event log.
     */
    public function start(?int $cursor = null): void
    {
        $this->shouldStop = false;
        $this->consecutiveFailures = 0;

        $cursor ??= $this->cursorStore->get() ?? 0;

        Log::info('[Signal] Starting Obelisk consumer', [
            'base_url' => config('atp-signals.obelisk.base_url'),
            'cursor' => $cursor,
            'collection' => $this->collectionFilter(),
        ]);

        while (! $this->shouldStop) {
            $drained = $this->tick($cursor);

            if ($drained === null) {
                $this->backoff();

                continue;
            }

            [$cursor, $count] = $drained;

            // A short page means the backlog is drained — wait before asking again.
            // A full page loops straight back so a backfill catches up at full speed.
            if ($count < $this->limit()) {
                $this->sleep($this->pollInterval());
            }
        }

        Log::info('[Signal] Obelisk consumer stopped', ['cursor' => $cursor]);
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Fetch and process a single page. Returns the events processed, or null when
     * the archive could not be reached (the cursor stays put).
     *
     * @param  int|null  $cursor  null = resume from the stored cursor.
     */
    public function pullOnce(?int $cursor = null): ?int
    {
        $result = $this->tick($cursor ?? $this->cursorStore->get() ?? 0);

        return $result === null ? null : $result[1];
    }

    /**
     * Drain the backlog to the head of the log, then return. Used by scheduled
     * catch-up runs that should not hold a process open.
     *
     * @return int Total events processed
     */
    public function drain(?int $cursor = null): int
    {
        $cursor ??= $this->cursorStore->get() ?? 0;
        $total = 0;

        while (true) {
            $result = $this->tick($cursor);

            if ($result === null) {
                break;
            }

            [$cursor, $count] = $result;
            $total += $count;

            if ($count < $this->limit()) {
                break;
            }
        }

        return $total;
    }

    /**
     * One page: fetch, process, persist the cursor.
     *
     * @return array{0: int, 1: int}|null [next cursor, events processed], or null on failure
     */
    protected function tick(int $cursor): ?array
    {
        try {
            $response = $this->client->getEvents(array_filter([
                'cursor' => $cursor,
                'limit' => $this->limit(),
                'collection' => $this->collectionFilter(),
            ], fn ($value) => $value !== null));
        } catch (\Throwable $e) {
            $this->consecutiveFailures++;

            Log::error('[Signal] Obelisk: getEvents failed', [
                'cursor' => $cursor,
                'attempt' => $this->consecutiveFailures,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->consecutiveFailures = 0;

        $events = is_array($response['events'] ?? null) ? $response['events'] : [];

        if ($events === []) {
            return [$cursor, 0];
        }

        $processed = $this->processor->process($events);

        if ($processed < count($events)) {
            Log::warning('[Signal] Obelisk: page contained events that could not be normalized', [
                'cursor' => $cursor,
                'fetched' => count($events),
                'processed' => $processed,
            ]);
        }

        // Advance only after the page has been handled.
        $next = (int) ($response['cursor'] ?? $cursor);
        $this->cursorStore->set($next);

        return [$next, count($events)];
    }

    protected function backoff(): void
    {
        $base = (int) config('atp-signals.connection.reconnect_delay', 5);
        $max = (int) config('atp-signals.connection.max_reconnect_delay', 60);

        $this->sleep(min($base * (2 ** min($this->consecutiveFailures - 1, 10)), $max));
    }

    protected function sleep(int $seconds): void
    {
        // Wake up every second so stop() is honoured promptly.
        for ($i = 0; $i < $seconds && ! $this->shouldStop; $i++) {
            sleep(1);
        }
    }

    protected function limit(): int
    {
        return (int) config('atp-signals.obelisk.pull.limit', 200);
    }

    protected function pollInterval(): int
    {
        return max(1, (int) config('atp-signals.obelisk.pull.poll_interval', 5));
    }

    protected function collectionFilter(): ?string
    {
        return config('atp-signals.obelisk.pull.collection');
    }
}
