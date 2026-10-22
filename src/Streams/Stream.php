<?php

namespace SocialDept\AtpSignals\Streams;

use Closure;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\TimerInterface;
use SocialDept\AtpSignals\Support\WebSocketConnection;
use Throwable;

/**
 * A websocket subscription for a caller that keeps its own position, such as
 * an indexer that saves the cursor with what it writes. Items arrive in
 * batches, each with the last seq it holds.
 *
 * The caller owns the cursor: it is asked for one on every connect, so a
 * batch whose handler failed is delivered again from the caller's last saved
 * position. Delivery is therefore at least once, and handlers should be
 * idempotent.
 *
 * A stream reconnects without giving up, backing off up to a cap, and keeps
 * a quiet connection honest with websocket pings, so a stream that carries
 * an item every few minutes is not mistaken for a dead one.
 */
abstract class Stream
{
    protected ?WebSocketConnection $connection = null;

    protected ?Closure $cursor = null;

    protected ?Closure $onBatch = null;

    protected ?Closure $onInfo = null;

    protected ?Closure $onError = null;

    /** @var array<int, mixed> */
    protected array $buffer = [];

    protected ?int $bufferSeq = null;

    protected ?TimerInterface $flushTimer = null;

    protected ?TimerInterface $keepaliveTimer = null;

    protected float $lastActivityAt = 0;

    protected int $attempts = 0;

    protected bool $stopping = false;

    /**
     * @param  int  $batchSize  Items per batch at most
     * @param  float  $batchWait  Seconds a partial batch waits before it is handed over
     * @param  int  $idleTimeout  Seconds with neither a frame nor a pong before reconnecting. 0 turns it off
     * @param  int  $maxBackoff  The longest wait between reconnects, in seconds
     * @param  (Closure(): WebSocketConnection)|null  $connections  Makes each connection, for tests
     */
    public function __construct(
        protected int $batchSize = 100,
        protected float $batchWait = 0.25,
        protected int $idleTimeout = 120,
        protected int $maxBackoff = 60,
        protected ?Closure $connections = null,
    ) {
    }

    /**
     * The URL to subscribe at, from a position. Null starts at the live tip.
     */
    abstract public function url(?int $cursor): string;

    /**
     * What one frame holds: items and the seq they end at, an advisory, or
     * nothing to deliver.
     *
     * @return array{items?: array<int, mixed>, seq?: int|null, info?: array{0: string, 1: string|null}}
     */
    abstract protected function decode(string $frame): array;

    /**
     * Websocket subprotocols to ask for.
     *
     * @return array<int, string>
     */
    protected function subProtocols(): array
    {
        return [];
    }

    /**
     * Each batch of items, with the last seq it holds.
     *
     * @param  Closure(array<int, mixed>, int|null): void  $handler
     */
    public function onBatch(Closure $handler): static
    {
        $this->onBatch = $handler;

        return $this;
    }

    /**
     * Server advisories, such as OutdatedCursor.
     *
     * @param  Closure(string, string|null): void  $handler  The name and the message
     */
    public function onInfo(Closure $handler): static
    {
        $this->onInfo = $handler;

        return $this;
    }

    /**
     * Connection and handler failures. The stream reconnects after each, unless
     * the handler calls stop(), as it should for a cursor the server no
     * longer holds.
     *
     * @param  Closure(Throwable): void  $handler
     */
    public function onError(Closure $handler): static
    {
        $this->onError = $handler;

        return $this;
    }

    /**
     * Subscribe and run until stop(). Blocks.
     *
     * @param  Closure(): (int|null)  $cursor  The position to resume from, asked on every connect
     */
    public function start(Closure $cursor): void
    {
        $this->open($cursor);
        $this->connection->run();
    }

    /**
     * Subscribe without running the event loop, so several streams can share
     * one: open each, then run the loop once.
     *
     * @param  Closure(): (int|null)  $cursor  The position to resume from, asked on every connect
     */
    public function open(Closure $cursor): void
    {
        $this->cursor = $cursor;
        $this->stopping = false;
        $this->connect();
    }

    /**
     * End this stream, handing over what it gathered, and leave the event
     * loop running for any others.
     */
    public function close(): void
    {
        $this->stopping = true;
        $this->flush();
        $this->cancelTimers();
        $this->connection?->close();
    }

    /**
     * End this stream and stop the event loop.
     */
    public function stop(): void
    {
        $this->close();
        $this->connection?->stop();
    }

    /**
     * Take one frame off the wire. Public so tests can feed frames.
     */
    public function receive(string $frame): void
    {
        $this->lastActivityAt = microtime(true);
        $this->attempts = 0;

        try {
            $decoded = $this->decode($frame);
        } catch (Throwable $e) {
            $this->fail($e);

            return;
        }

        if (isset($decoded['info'])) {
            if ($this->onInfo) {
                ($this->onInfo)($decoded['info'][0], $decoded['info'][1]);
            }

            return;
        }

        if (($decoded['items'] ?? []) === []) {
            return;
        }

        array_push($this->buffer, ...$decoded['items']);
        $this->bufferSeq = $decoded['seq'] ?? $this->bufferSeq;

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        } elseif ($this->flushTimer === null && $this->connection) {
            $this->flushTimer = $this->connection->getLoop()->addTimer($this->batchWait, fn () => $this->flush());
        }
    }

    /**
     * Hand the gathered items to the batch handler. A handler that throws
     * closes the connection, so the items come again from the caller's saved
     * position.
     */
    public function flush(): void
    {
        if ($this->flushTimer && $this->connection) {
            $this->connection->getLoop()->cancelTimer($this->flushTimer);
        }

        $this->flushTimer = null;

        if ($this->buffer === []) {
            return;
        }

        [$items, $seq] = [$this->buffer, $this->bufferSeq];
        $this->buffer = [];

        try {
            if ($this->onBatch) {
                ($this->onBatch)($items, $seq);
            }
        } catch (Throwable $e) {
            $this->fail($e);
            $this->connection?->close();
        }
    }

    protected function connect(): void
    {
        $this->connection = $this->connections ? ($this->connections)() : new WebSocketConnection($this->connection?->getLoop());
        $this->buffer = [];
        $this->bufferSeq = null;

        $this->connection
            ->onMessage(fn (string $message) => $this->receive($message))
            ->onClose(fn () => $this->closed())
            ->onError(fn (Throwable $e) => $this->fail($e));

        $this->connection->connect($this->url(($this->cursor)()), $this->subProtocols())->then(
            function () {
                $this->lastActivityAt = microtime(true);
                $this->connection->getConnection()?->on('pong', fn () => $this->lastActivityAt = microtime(true));
                $this->connection->getConnection()?->on('ping', fn () => $this->lastActivityAt = microtime(true));
                $this->keepalive();
            },
            fn () => $this->closed(),
        );
    }

    protected function closed(): void
    {
        $this->cancelTimers();
        $this->buffer = [];

        if ($this->stopping) {
            return;
        }

        $delay = min($this->maxBackoff, 2 ** min($this->attempts, 10));
        $this->attempts++;

        $this->connection->getLoop()->addTimer($delay, function () {
            if (! $this->stopping) {
                $this->connect();
            }
        });
    }

    /**
     * Ping on a quiet connection, and close one that answers nothing within
     * the idle timeout.
     */
    protected function keepalive(): void
    {
        if ($this->idleTimeout <= 0) {
            return;
        }

        $interval = max(1, intdiv($this->idleTimeout, 4));

        $this->keepaliveTimer = $this->connection->getLoop()->addPeriodicTimer($interval, function () use ($interval) {
            $idle = microtime(true) - $this->lastActivityAt;

            if ($idle > $this->idleTimeout) {
                $this->connection->close();
            } elseif ($idle >= $interval) {
                $this->connection->getConnection()?->send(new Frame('', true, Frame::OP_PING));
            }
        });
    }

    protected function fail(Throwable $e): void
    {
        if ($this->onError) {
            ($this->onError)($e);
        }
    }

    protected function cancelTimers(): void
    {
        $loop = $this->connection?->getLoop();

        foreach ([$this->flushTimer, $this->keepaliveTimer] as $timer) {
            if ($timer && $loop) {
                $loop->cancelTimer($timer);
            }
        }

        $this->flushTimer = $this->keepaliveTimer = null;
    }
}
