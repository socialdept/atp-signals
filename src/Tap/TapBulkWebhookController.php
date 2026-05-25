<?php

namespace SocialDept\AtpSignals\Tap;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpSignals\Jobs\ProcessSignalJob;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\Services\SignalRegistry;

class TapBulkWebhookController extends Controller
{
    public function __invoke(Request $request, EventDispatcher $dispatcher, SignalRegistry $registry): JsonResponse
    {
        if (! $this->authenticate($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $events = $request->input('events');

        if (! is_array($events)) {
            return response()->json(['error' => 'Invalid payload: missing or invalid events array'], 422);
        }

        if (empty($events)) {
            return response()->json(['status' => 'ok', 'processed' => 0]);
        }

        $normalizer = new TapEventNormalizer();
        $processed = 0;

        foreach ($events as $data) {
            if (! isset($data['type'])) {
                continue;
            }

            try {
                $event = $normalizer->normalize($data);

                if ($this->shouldDebug()) {
                    Log::debug('[Signal] Tap bulk webhook event', [
                        'tap_id' => $data['id'] ?? null,
                        'type' => $data['type'],
                        'did' => $event->did,
                        'kind' => $event->kind,
                        'collection' => $event->commit?->collection,
                        'action' => $event->commit?->operation?->value,
                        'backfill' => $event->backfill,
                    ]);
                }

                if ($this->shouldQueue()) {
                    $this->dispatchToQueue($event, $registry);
                } else {
                    $dispatcher->dispatch($event);
                }

                $processed++;
            } catch (\Throwable $e) {
                Log::warning('[Signal] Tap bulk: failed to process event', [
                    'tap_id' => $data['id'] ?? null,
                    'type' => $data['type'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['status' => 'ok', 'processed' => $processed]);
    }

    protected function authenticate(Request $request): bool
    {
        $password = config('atp-signals.tap.admin_password');

        if (! $password) {
            return true;
        }

        $header = $request->header('Authorization');

        if (! $header || ! str_starts_with($header, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($header, 6));

        return $decoded === "admin:{$password}";
    }

    protected function shouldDebug(): bool
    {
        return config('atp-signals.debug', false);
    }

    protected function shouldQueue(): bool
    {
        return config('atp-signals.tap.queue_events', true);
    }

    protected function dispatchToQueue($event, SignalRegistry $registry): void
    {
        $signals = $registry->getMatchingSignals($event);

        foreach ($signals as $signal) {
            try {
                ProcessSignalJob::dispatch($signal, $event)
                    ->onConnection(config('atp-signals.tap.queue_connection'))
                    ->onQueue(config('atp-signals.tap.queue_name', 'tap'));
            } catch (\Throwable $e) {
                Log::error('[Signal] Tap bulk: Failed to queue signal', [
                    'signal' => get_class($signal),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
