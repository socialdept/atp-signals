<?php

namespace SocialDept\AtpSignals\Services;

use Illuminate\Support\Facades\Log;
use React\EventLoop\TimerInterface;
use SocialDept\AtpSignals\Contracts\CursorStore;
use SocialDept\AtpSignals\Events\CursorOutdated;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Exceptions\ConnectionException;
use SocialDept\AtpSignals\Storage\CursorStoreFactory;
use SocialDept\AtpSignals\Support\JetstreamV2Translator;
use SocialDept\AtpSignals\Support\WebSocketConnection;

class JetstreamConsumer
{
    protected CursorStore $cursorStore;

    protected SignalRegistry $signalRegistry;

    protected EventDispatcher $eventDispatcher;

    protected ?WebSocketConnection $connection = null;

    protected int $reconnectAttempts = 0;

    protected bool $shouldStop = false;

    protected ?\Exception $lastError = null;

    protected ?TimerInterface $watchdogTimer = null;

    protected float $lastMessageAt = 0;

    public function __construct(
        CursorStore $cursorStore,
        SignalRegistry $signalRegistry,
        EventDispatcher $eventDispatcher
    ) {
        $this->cursorStore = $cursorStore;
        $this->signalRegistry = $signalRegistry;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Start consuming the Jetstream.
     */
    public function start(?int $cursor = null): void
    {
        $this->shouldStop = false;
        $this->lastError = null;

        // null = use stored cursor, 0 = start fresh (no cursor), >0 = specific cursor
        $cursor = $this->resolveCursor($cursor);

        $url = $this->buildWebSocketUrl($cursor);

        Log::info('[Signal] Starting Jetstream consumer', [
            'url' => $url,
            'cursor' => $cursor ?? 'none (fresh start)',
            'mode' => 'jetstream',
            'version' => $this->version(),
        ]);

        $this->connect($url);

        // Check if we exited due to a fatal error (after all reconnection attempts)
        if ($this->lastError) {
            throw $this->lastError;
        }

        // If we get here without intentionally stopping, something went wrong
        if (! $this->shouldStop) {
            throw new ConnectionException('Jetstream connection closed unexpectedly');
        }
    }

    /**
     * Stop consuming the Jetstream.
     */
    public function stop(): void
    {
        $this->shouldStop = true;

        $this->disarmWatchdog();

        if ($this->connection) {
            $this->connection->close();
        }

        Log::info('[Signal] Jetstream consumer stopped');
    }

    /**
     * The Jetstream wire version to speak (1 or 2).
     */
    protected function version(): int
    {
        return (int) config('atp-signals.jetstream_version', 1);
    }

    /**
     * Connect and run the event loop (blocking). Reconnects re-enter via
     * establish() on a loop timer rather than calling this again.
     */
    protected function connect(string $url): void
    {
        $this->establish($url);
        $this->connection->run();
    }

    /**
     * Open a WebSocket connection asynchronously on the shared event loop.
     */
    protected function establish(string $url): void
    {
        $this->connection = new WebSocketConnection();

        // Set up event handlers
        $this->connection
            ->onMessage(function (string $message) {
                $this->lastMessageAt = microtime(true);
                $this->handleMessage($message);
            })
            ->onClose(function (?int $code, ?string $reason) {
                $this->handleClose($code, $reason);
            })
            ->onError(function (\Exception $e) {
                $this->handleError($e);
            });

        $subProtocols = $this->version() === 2 ? ['xrpc.v1.json'] : [];

        // Connect to the WebSocket endpoint
        $this->connection->connect($url, $subProtocols)->then(
            function () {
                $this->reconnectAttempts = 0;
                $this->armWatchdog();
                Log::info('[Signal] Connected to Jetstream successfully');
            },
            function (\Exception $e) {
                Log::error('[Signal] Could not connect to Jetstream', [
                    'error' => $e->getMessage(),
                ]);

                if (! $this->shouldStop) {
                    $this->attemptReconnect();
                }
            }
        );
    }

    /**
     * Handle incoming WebSocket message.
     */
    protected function handleMessage(string $message): void
    {
        try {
            $data = json_decode($message, true);

            if (! $data) {
                Log::warning('[Signal] Failed to decode message');

                return;
            }

            if ($this->version() === 2) {
                $this->handleV2Payload(JetstreamV2Translator::payload($data));

                return;
            }

            $event = SignalEvent::fromArray($data);

            // Update cursor
            $this->cursorStore->set($event->timeUs);

            // Dispatch to matching signals
            $this->eventDispatcher->dispatch($event);

        } catch (\Exception $e) {
            Log::error('[Signal] Error handling message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle a decoded Jetstream v2 payload.
     */
    protected function handleV2Payload(array $payload): void
    {
        if (JetstreamV2Translator::isInfo($payload)) {
            Log::warning('[Signal] Jetstream advisory', [
                'name' => $payload['name'] ?? null,
                'message' => $payload['message'] ?? null,
            ]);

            if (($payload['name'] ?? null) === 'OutdatedCursor') {
                event(new CursorOutdated(
                    requestedCursor: $this->cursorStore->get(),
                    message: $payload['message'] ?? null,
                ));
            }

            return;
        }

        $event = JetstreamV2Translator::toSignalEvent($payload);

        if ($event === null) {
            Log::warning('[Signal] Unrecognized v2 payload', [
                'type' => $payload['$type'] ?? null,
            ]);

            return;
        }

        if ($event->seq !== null) {
            $this->cursorStore->set($event->seq);
        }

        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Handle WebSocket connection close.
     */
    protected function handleClose(?int $code, ?string $reason): void
    {
        Log::warning('[Signal] Connection closed', [
            'code' => $code,
            'reason' => $reason ?: 'none',
            'reconnect_attempts' => $this->reconnectAttempts,
        ]);

        // Attempt reconnection if enabled
        if (! $this->shouldStop) {
            $this->attemptReconnect();
        }
    }

    /**
     * Handle WebSocket connection error.
     */
    protected function handleError(\Exception $error): void
    {
        Log::error('[Signal] Connection error', [
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
        ]);
    }

    /**
     * Schedule a reconnect on the event loop with exponential backoff. A loop
     * timer (rather than a blocking sleep) keeps the loop responsive and the
     * call stack flat across repeated reconnects.
     */
    protected function attemptReconnect(): void
    {
        $maxAttempts = config('atp-signals.connection.reconnect_attempts', 5);

        if ($this->reconnectAttempts >= $maxAttempts) {
            Log::error('[Signal] Max reconnection attempts reached');

            $this->lastError = new ConnectionException('Failed to reconnect to Jetstream after '.$maxAttempts.' attempts');
            $this->disarmWatchdog();
            $this->connection?->stop();

            return;
        }

        $this->reconnectAttempts++;

        // Calculate exponential backoff delay
        $baseDelay = config('atp-signals.connection.reconnect_delay', 5);
        $maxDelay = config('atp-signals.connection.max_reconnect_delay', 60);

        $delay = min(
            $baseDelay * (2 ** ($this->reconnectAttempts - 1)),
            $maxDelay
        );

        Log::info('[Signal] Attempting to reconnect', [
            'attempt' => $this->reconnectAttempts,
            'max_attempts' => $maxAttempts,
            'delay' => $delay,
        ]);

        $this->connection->getLoop()->addTimer($delay, function () {
            if ($this->shouldStop) {
                return;
            }

            $this->establish($this->buildWebSocketUrl($this->resolveCursor()));
        });
    }

    /**
     * Watch for silent dead connections: a Jetstream that sends nothing for
     * longer than the idle timeout is treated as gone and reconnected, even
     * when the socket never reported a close.
     */
    protected function armWatchdog(): void
    {
        $timeout = (int) config('atp-signals.connection.idle_timeout', 60);

        if ($timeout <= 0) {
            return;
        }

        $this->disarmWatchdog();
        $this->lastMessageAt = microtime(true);

        $interval = max(1, (int) ceil($timeout / 4));

        $this->watchdogTimer = $this->connection->getLoop()->addPeriodicTimer($interval, function () use ($timeout) {
            if ($this->shouldStop || ! $this->connection?->isConnected()) {
                return;
            }

            if (microtime(true) - $this->lastMessageAt > $timeout) {
                Log::warning('[Signal] No messages within idle timeout; reconnecting', [
                    'idle_timeout' => $timeout,
                ]);

                $this->connection->close();
            }
        });
    }

    protected function disarmWatchdog(): void
    {
        if ($this->watchdogTimer && $this->connection) {
            $this->connection->getLoop()->cancelTimer($this->watchdogTimer);
        }

        $this->watchdogTimer = null;
    }

    /**
     * Resolve the position to connect from.
     *
     * Used by both the initial start and every reconnect: a reconnect that
     * resolved the cursor differently would silently restart from live once
     * the stored position is still empty, losing the v1 seed it was given.
     *
     * @param  int|null  $cursor  null = use the stored position, 0 = start
     *                            fresh, >0 = an explicit position
     * @return int|null The cursor to send, or null to start from live
     */
    protected function resolveCursor(?int $cursor = null): ?int
    {
        if ($cursor === null) {
            $cursor = $this->cursorStore->get();
        }

        // A v2 consumer with no position yet can seed from the v1 cursor: the
        // server reads cursor values >= 1e15 as unix-microsecond timestamps,
        // which is exactly what a v1 time_us cursor is.
        if ($cursor === null && $this->version() === 2) {
            $cursor = $this->seedCursorFromV1();
        }

        // A cursor of 0 means "fresh start" here, so it is not sent.
        return $cursor > 0 ? $cursor : null;
    }

    /**
     * Seed a fresh v2 cursor from the legacy v1 position, if one exists.
     */
    protected function seedCursorFromV1(): ?int
    {
        $timeUs = CursorStoreFactory::make()->get();

        if ($timeUs !== null && $timeUs > 0) {
            Log::info('[Signal] Seeding v2 cursor from the v1 time_us position', [
                'time_us' => $timeUs,
            ]);
        }

        return $timeUs;
    }

    /**
     * Build the WebSocket URL with optional cursor and collection filters.
     */
    protected function buildWebSocketUrl(?int $cursor = null): string
    {
        if ($this->version() === 2) {
            return $this->buildV2WebSocketUrl($cursor);
        }

        $baseUrl = config('atp-signals.websocket_url', 'wss://jetstream2.us-east.bsky.network');
        $url = rtrim($baseUrl, '/').'/subscribe';

        $params = [];

        // Add cursor parameter if provided
        if ($cursor !== null) {
            $params[] = 'cursor='.$cursor;
        }

        // Add collection filters from all registered signals
        // If ANY signal wants all collections (returns null), don't filter at all
        $signals = $this->signalRegistry->all();
        $hasWildcardSignal = $signals->contains(fn ($signal) => $signal->collections() === null);

        Log::debug('[Signal] Building Jetstream URL', [
            'registered_signals' => $signals->map(fn ($s) => get_class($s))->values()->toArray(),
            'has_wildcard_signal' => $hasWildcardSignal,
        ]);

        if (! $hasWildcardSignal) {
            $collections = $signals
                ->flatMap(fn ($signal) => $signal->collections() ?? [])
                ->unique()
                ->filter()
                ->values();

            Log::debug('[Signal] Collection filters', [
                'collections' => $collections->toArray(),
            ]);

            if ($collections->isNotEmpty()) {
                foreach ($collections as $collection) {
                    // Don't encode wildcards - Jetstream expects literal *
                    $encoded = str_replace('%2A', '*', urlencode($collection));
                    $params[] = 'wantedCollections='.$encoded;
                }
            }
        }

        if (! empty($params)) {
            $url .= '?'.implode('&', $params);
        }

        return $url;
    }

    /**
     * Build the Jetstream v2 subscribeEvents URL. v2 renames the filters
     * (collections, kinds) and adds a kinds filter so the server can skip
     * event kinds no signal handles.
     */
    protected function buildV2WebSocketUrl(?int $cursor = null): string
    {
        $baseUrl = config('atp-signals.websocket_url_v2', 'wss://jetstream.us-west.bsky.network');
        $url = rtrim($baseUrl, '/').'/xrpc/network.bsky.jetstream.subscribeEvents';

        $params = [];

        if ($cursor !== null) {
            $params[] = 'cursor='.$cursor;
        }

        $signals = $this->signalRegistry->all();

        $kinds = $signals
            ->flatMap(fn ($signal) => $signal->eventTypes())
            ->map(fn ($kind) => $kind instanceof \BackedEnum ? $kind->value : $kind)
            ->unique()
            ->filter()
            ->values();

        // A collections filter constrains commits only, so it is meaningless
        // (and rejected by the server) when no signal handles commits.
        $wantsCommits = $kinds->contains('commit');
        $hasWildcardSignal = $signals->contains(fn ($signal) => $signal->collections() === null);

        if ($wantsCommits && ! $hasWildcardSignal) {
            $collections = $signals
                ->flatMap(fn ($signal) => $signal->collections() ?? [])
                ->unique()
                ->filter()
                ->values();

            foreach ($collections as $collection) {
                // Don't encode wildcards - Jetstream expects literal *
                $encoded = str_replace('%2A', '*', urlencode($collection));
                $params[] = 'collections='.$encoded;
            }
        }

        foreach ($kinds as $kind) {
            $params[] = 'kinds='.$kind;
        }

        if (! empty($params)) {
            $url .= '?'.implode('&', $params);
        }

        return $url;
    }
}
