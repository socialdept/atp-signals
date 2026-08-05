<?php

namespace SocialDept\AtpSignals\Obelisk;

use Illuminate\Support\Facades\Log;
use SocialDept\AtpSignals\Services\EventDispatcher;

/**
 * Runs a batch of Obelisk events through the Signal pipeline in cursor order.
 *
 * Order matters: Obelisk delivers a batch ascending by event id, and a batch can
 * contain several events for the same URI (create then update then delete). This
 * dispatches them sequentially so the last write wins, which is why the queue
 * boundary is the batch job rather than one job per event.
 *
 * Shared by the webhook controller (push) and the pull consumer.
 */
class ObeliskBatchProcessor
{
    public function __construct(
        protected EventDispatcher $dispatcher,
        protected ObeliskEventNormalizer $normalizer,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return int Number of events dispatched
     */
    public function process(array $events): int
    {
        $processed = 0;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            try {
                $signalEvent = $this->normalizer->normalize($event);
            } catch (\Throwable $e) {
                // A malformed event should not strand the rest of the batch. The
                // archive keeps it, so a rewind can replay once the cause is fixed.
                Log::warning('[Signal] Obelisk: skipping malformed event', [
                    'cursor' => $event['cursor'] ?? null,
                    'uri' => $event['uri'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($this->shouldDebug()) {
                Log::debug('[Signal] Obelisk event', [
                    'cursor' => $signalEvent->cursor,
                    'did' => $signalEvent->did,
                    'collection' => $signalEvent->commit?->collection,
                    'action' => $signalEvent->commit?->operation->value,
                    'backfill' => $signalEvent->backfill,
                ]);
            }

            $this->dispatcher->dispatch($signalEvent);
            $processed++;
        }

        return $processed;
    }

    protected function shouldDebug(): bool
    {
        return (bool) config('atp-signals.debug', false);
    }
}
