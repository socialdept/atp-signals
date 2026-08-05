<?php

namespace SocialDept\AtpSignals\Obelisk;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use SocialDept\AtpSignals\Jobs\ProcessObeliskBatchJob;

/**
 * Receives batched, HMAC-signed event deliveries from an Obelisk archive.
 *
 * Obelisk owns the cursor and only advances it on a 2xx, so this endpoint must
 * not answer 200 until the batch is durably accepted — queued, or handled
 * inline. Anything else returns non-2xx, and Obelisk redelivers the same batch
 * after a backoff with nothing lost.
 */
class ObeliskWebhookController extends Controller
{
    /** Hint to the archive for how long to hold off when we are saturated. */
    private const RETRY_AFTER_SECONDS = 60;

    public function __invoke(Request $request, ObeliskBatchProcessor $processor): JsonResponse
    {
        $body = $request->getContent();

        if (! $this->verifySignature($request, $body)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || ! is_array($payload['events'] ?? null)) {
            return response()->json(['error' => 'Invalid payload: missing or invalid events array'], 422);
        }

        $events = $payload['events'];

        if ($events === []) {
            return response()->json(['status' => 'ok', 'processed' => 0]);
        }

        $subscription = is_string($payload['subscription'] ?? null) ? $payload['subscription'] : null;

        if (($depth = $this->saturatedDepth()) !== null) {
            Log::warning('[Signal] Obelisk: refusing batch, queue is saturated', [
                'subscription' => $subscription,
                'queue' => config('atp-signals.obelisk.queue_name', 'obelisk'),
                'depth' => $depth,
                'max' => (int) config('atp-signals.obelisk.max_queue_depth'),
                'events' => count($events),
            ]);

            return response()
                ->json(['error' => 'Queue saturated', 'depth' => $depth], 503)
                ->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        try {
            $processed = $this->shouldQueue()
                ? $this->queueBatch($events, $subscription)
                : $processor->process($events);
        } catch (\Throwable $e) {
            // Never swallow this: a 200 here would advance Obelisk's cursor past
            // events nothing has handled.
            Log::error('[Signal] Obelisk: failed to accept batch', [
                'subscription' => $subscription,
                'cursor' => $payload['cursor'] ?? null,
                'events' => count($events),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to accept batch'], 500);
        }

        return response()->json(['status' => 'ok', 'processed' => $processed]);
    }

    /**
     * Current queue depth when it is at or over the configured ceiling, else null.
     *
     * This is the flow-control valve. Accepting a batch is cheap (a Redis push)
     * while handling one is not, so without a brake the archive — which delivers
     * as fast as we answer — will happily outrun the worker and pile unbounded
     * jobs into the queue. Each job carries a whole batch of record bodies, so
     * the queue runs out of memory long before it runs out of job slots.
     *
     * Refusing is safe: the archive advances its cursor only on a 2xx, so it
     * backs off and re-sends the same batch. Nothing is lost, we just stop
     * taking work we cannot keep up with.
     *
     * Only meaningful when queueing. Handling inline makes the request itself
     * the backpressure, since the archive waits for the response.
     */
    protected function saturatedDepth(): ?int
    {
        $max = (int) config('atp-signals.obelisk.max_queue_depth', 0);

        if ($max <= 0 || ! $this->shouldQueue()) {
            return null;
        }

        try {
            $depth = Queue::connection(config('atp-signals.obelisk.queue_connection'))
                ->size(config('atp-signals.obelisk.queue_name', 'obelisk'));
        } catch (\Throwable $e) {
            // Fail open. If we cannot measure the queue, refusing every batch
            // would be a self-inflicted outage; the enqueue below will surface
            // the real problem with a 500 if the connection is genuinely down.
            Log::warning('[Signal] Obelisk: could not measure queue depth', ['error' => $e->getMessage()]);

            return null;
        }

        return $depth >= $max ? $depth : null;
    }

    /**
     * Verify the HMAC-SHA256 signature over the raw request body.
     *
     * Fails closed: with no configured secret there is nothing to verify against,
     * so the delivery is rejected rather than trusted.
     */
    protected function verifySignature(Request $request, string $body): bool
    {
        if (! config('atp-signals.obelisk.verify_signature', true)) {
            return true;
        }

        $secret = config('atp-signals.obelisk.webhook_secret');

        if (! $secret) {
            Log::error('[Signal] Obelisk: no webhook secret configured, rejecting delivery. '
                .'Set OBELISK_WEBHOOK_SECRET to the value createWebhook returned.');

            return false;
        }

        $signature = $request->header('X-Obelisk-Signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return int Number of events handed to the queue
     */
    protected function queueBatch(array $events, ?string $subscription): int
    {
        ProcessObeliskBatchJob::dispatch($events, $subscription)
            ->onConnection(config('atp-signals.obelisk.queue_connection'))
            ->onQueue(config('atp-signals.obelisk.queue_name', 'obelisk'));

        return count($events);
    }

    protected function shouldQueue(): bool
    {
        return (bool) config('atp-signals.obelisk.queue_events', true);
    }
}
