<?php

namespace SocialDept\AtpSignals\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpCbor\Core\CAR;
use SocialDept\AtpCbor\Core\CBOR;
use SocialDept\AtpCbor\Core\CID;
use SocialDept\AtpSignals\Contracts\CursorStore;
use SocialDept\AtpSignals\Events\AccountEvent;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\IdentityEvent;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Exceptions\ConnectionException;
use SocialDept\AtpSignals\Support\WebSocketConnection;

class FirehoseConsumer
{
    protected CursorStore $cursorStore;

    protected SignalRegistry $signalRegistry;

    protected EventDispatcher $eventDispatcher;

    protected ?WebSocketConnection $connection = null;

    protected int $reconnectAttempts = 0;

    protected bool $shouldStop = false;

    protected ?\Exception $lastError = null;

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
     * Start consuming the Firehose.
     */
    public function start(?int $cursor = null): void
    {
        $this->shouldStop = false;
        $this->lastError = null;

        // Get cursor from storage if not explicitly provided
        // null = use stored cursor, 0 = start fresh (no cursor), >0 = specific cursor
        if ($cursor === null) {
            $cursor = $this->cursorStore->get();
        }

        // If cursor is explicitly 0, don't send it (fresh start)
        $url = $this->buildWebSocketUrl($cursor > 0 ? $cursor : null);

        Log::info('[Signal] Starting Firehose consumer', [
            'url' => $url,
            'cursor' => $cursor > 0 ? $cursor : 'none (fresh start)',
            'mode' => 'firehose',
        ]);

        $this->connect($url);

        // Check if we exited due to a fatal error (after all reconnection attempts)
        if ($this->lastError) {
            throw $this->lastError;
        }

        // If we get here without intentionally stopping, something went wrong
        if (! $this->shouldStop) {
            throw new ConnectionException('Firehose connection closed unexpectedly');
        }
    }

    /**
     * Stop consuming the Firehose.
     */
    public function stop(): void
    {
        $this->shouldStop = true;

        if ($this->connection) {
            $this->connection->close();
        }

        Log::info('[Signal] Firehose consumer stopped');
    }

    /**
     * Connect to the Firehose WebSocket.
     */
    protected function connect(string $url): void
    {
        $this->connection = new WebSocketConnection();

        // Set up event handlers
        $this->connection
            ->onMessage(function (string $message) {
                $this->handleMessage($message);
            })
            ->onClose(function (?int $code, ?string $reason) {
                $this->handleClose($code, $reason);
            })
            ->onError(function (\Exception $e) {
                $this->handleError($e);
            });

        // Connect to the WebSocket endpoint
        $this->connection->connect($url)->then(
            function () {
                $this->reconnectAttempts = 0;
                Log::info('[Signal] Connected to Firehose successfully');
            },
            function (\Exception $e) {
                Log::error('[Signal] Could not connect to Firehose', [
                    'error' => $e->getMessage(),
                ]);

                if (! $this->shouldStop) {
                    $this->attemptReconnect();
                }
            }
        );

        // Run the event loop (blocking)
        $this->connection->run();
    }

    /**
     * Handle incoming WebSocket message (binary CBOR format).
     */
    protected function handleMessage(string $message): void
    {
        try {
            // 1. Decode CBOR header
            [$header, $remainder] = rescue(fn () => CBOR::decodeFirst($message), [[], '']);

            if (! Arr::has($header, ['t', 'op'])) {
                Log::debug('[Signal] Invalid header', ['header' => $header]);

                return;
            }

            if ($header['op'] !== 1) {
                return;
            }

            $kind = $header['t'];

            // 2. Decode CBOR payload
            $payload = rescue(fn () => CBOR::decode($remainder ?? []));

            if (! $payload) {
                Log::warning('[Signal] Failed to decode payload');

                return;
            }

            // 3. Process based on kind
            match ($kind) {
                '#commit' => $this->handleCommit($payload),
                '#identity' => $this->handleIdentity($payload),
                '#account' => $this->handleAccount($payload),
                default => null,
            };

        } catch (\Exception $e) {
            Log::error('[Signal] Error handling Firehose message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle commit event from Firehose.
     */
    protected function handleCommit(array $payload): void
    {
        $required = ['seq', 'rebase', 'repo', 'commit', 'rev', 'since', 'blocks', 'ops', 'time'];
        if (! Arr::has($payload, $required)) {
            return;
        }

        $did = $payload['repo'];
        $rev = $payload['rev'];
        $time = $payload['time'];
        $timeUs = $payload['seq'] ?? 0; // Use seq as time_us equivalent

        // Parse CAR blocks (returns CID => block data map)
        $records = $payload['blocks'];

        $blocks = [];
        if (! empty($records)) {
            $blocks = rescue(fn () => CAR::blockMap($records, $did), [], function (\Throwable $e) {
                Log::warning('[Signal] Failed to parse CAR blocks', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            });
        }

        // Process operations
        $ops = $payload['ops'];

        foreach ($ops as $op) {
            if (! Arr::has($op, ['cid', 'path', 'action'])) {
                continue;
            }

            $action = $op['action'];
            if (! in_array($action, ['create', 'update', 'delete'])) {
                continue;
            }

            $cid = $op['cid'];
            $path = $op['path'];
            $collection = '';
            $rkey = '';

            if (str_contains($path, '/')) {
                [$collection, $rkey] = explode('/', $path, 2);
            }

            // Get record data from blocks using the op CID
            // Convert CID to string if it's an object
            $cidStr = $cid instanceof CID ? $cid->toString() : $cid;

            // For delete operations, there won't be a record
            $record = [];
            if ($action !== 'delete' && isset($blocks[$cidStr])) {
                // Decode the CBOR block to get the record data
                $decoded = rescue(fn () => CBOR::decode($blocks[$cidStr]));
                if (is_array($decoded)) {
                    $record = $this->normalizeCids($decoded);
                }
            }

            // Convert to SignalEvent format for compatibility
            $event = $this->buildSignalEvent($did, $timeUs, $action, $collection, $rkey, $rev, $cidStr, $record);

            // Dispatch event with cursor update
            $this->dispatchSignalEvent($event);
        }
    }

    /**
     * Build SignalEvent from Firehose data for compatibility.
     */
    protected function buildSignalEvent(
        string $did,
        int $timeUs,
        string $operation,
        string $collection,
        string $rkey,
        string $rev,
        ?string $cid,
        array $record
    ): SignalEvent {
        // Record is already the decoded data, or empty array for deletes
        $recordValue = ! empty($record) ? (object) $record : null;

        $commitEvent = new CommitEvent(
            rev: $rev,
            operation: $operation,
            collection: $collection,
            rkey: $rkey,
            record: $recordValue,
            cid: $cid
        );

        return new SignalEvent(
            did: $did,
            timeUs: $timeUs,
            kind: 'commit',
            commit: $commitEvent
        );
    }

    /**
     * Normalize CID objects to AT Protocol link format.
     */
    protected function normalizeCids(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof CID) {
                // Convert CID to AT Protocol link format
                $data[$key] = ['$link' => $value->toString()];
            } elseif (is_array($value)) {
                $data[$key] = $this->normalizeCids($value);
            }
        }

        return $data;
    }

    /**
     * Dispatch a SignalEvent with cursor update.
     */
    protected function dispatchSignalEvent(SignalEvent $event): void
    {
        // Update cursor
        $this->cursorStore->set($event->timeUs);

        // Dispatch to matching signals
        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Handle identity event from Firehose.
     */
    protected function handleIdentity(array $payload): void
    {
        // Validate required fields
        if (! isset($payload['did'])) {
            Log::debug('[Signal] Invalid identity payload - missing did', ['payload' => $payload]);

            return;
        }

        $did = $payload['did'];
        $handle = $payload['handle'] ?? null;
        $seq = $payload['seq'] ?? 0;
        $time = $payload['time'] ?? null;
        $timeUs = $seq; // Use seq as timeUs equivalent for cursor management

        // Create IdentityEvent
        $identityEvent = new IdentityEvent(
            did: $did,
            handle: $handle,
            seq: $seq,
            time: $time
        );

        // Create SignalEvent wrapper
        $event = new SignalEvent(
            did: $did,
            timeUs: $timeUs,
            kind: 'identity',
            identity: $identityEvent
        );

        // Dispatch event with cursor update
        $this->dispatchSignalEvent($event);
    }

    /**
     * Handle account event from Firehose.
     */
    protected function handleAccount(array $payload): void
    {
        // Validate required fields
        if (! isset($payload['did'], $payload['active'])) {
            Log::debug('[Signal] Invalid account payload - missing required fields', ['payload' => $payload]);

            return;
        }

        $did = $payload['did'];
        $active = (bool) $payload['active'];
        $status = $payload['status'] ?? null;
        $seq = $payload['seq'] ?? 0;
        $time = $payload['time'] ?? null;
        $timeUs = $seq; // Use seq as timeUs equivalent for cursor management

        // Create AccountEvent
        $accountEvent = new AccountEvent(
            did: $did,
            active: $active,
            status: $status,
            seq: $seq,
            time: $time
        );

        // Create SignalEvent wrapper
        $event = new SignalEvent(
            did: $did,
            timeUs: $timeUs,
            kind: 'account',
            account: $accountEvent
        );

        // Dispatch event with cursor update
        $this->dispatchSignalEvent($event);
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
     * Attempt to reconnect to the Firehose with exponential backoff.
     */
    protected function attemptReconnect(): void
    {
        $maxAttempts = config('atp-signals.connection.reconnect_attempts', 5);

        if ($this->reconnectAttempts >= $maxAttempts) {
            Log::error('[Signal] Max reconnection attempts reached');

            $this->lastError = new ConnectionException('Failed to reconnect to Firehose after '.$maxAttempts.' attempts');
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

        sleep($delay);

        $cursor = $this->cursorStore->get();
        $url = $this->buildWebSocketUrl($cursor);

        $this->connect($url);
    }

    /**
     * Build the WebSocket URL with optional cursor.
     * Note: Raw firehose does NOT support collection filtering.
     */
    protected function buildWebSocketUrl(?int $cursor = null): string
    {
        $host = config('atp-signals.firehose.host', 'bsky.network');
        $url = "wss://{$host}/xrpc/com.atproto.sync.subscribeRepos";

        $params = [];

        // Add cursor parameter if provided
        if ($cursor !== null) {
            $params[] = 'cursor='.$cursor;
        }

        if (! empty($params)) {
            $url .= '?'.implode('&', $params);
        }

        return $url;
    }
}
