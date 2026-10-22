<?php

namespace SocialDept\AtpSignals\Tests\Unit;

use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

use RuntimeException;
use SocialDept\AtpSignals\Jetstream\JetstreamStream;
use SocialDept\AtpSignals\Support\WebSocketConnection;

/**
 * The Jetstream v2 stream for callers that keep their own cursor: filters,
 * batches with their last seq, advisories, and redelivery from the caller's
 * position when a batch handler fails.
 */
class JetstreamStreamTest extends TestCase
{
    protected function commit(int $seq, string $rkey = 'r'): string
    {
        return json_encode(['$type' => 'message', 'payload' => [
            '$type' => 'network.bsky.jetstream.subscribeEvents#commit',
            'did' => 'did:plc:alice', 'seq' => $seq, 'time' => '2026-10-21T12:00:00.000000Z',
            'operation' => 'create', 'collection' => 'xyz.statusphere.status', 'rkey' => $rkey.$seq,
            'rev' => '3krev', 'cid' => 'bafy', 'record' => ['$type' => 'xyz.statusphere.status', 'status' => '🔥'],
        ]]);
    }

    /**
     * A stream on a fake connection, recording each URL it connects to.
     *
     * @param  array<int, string>  $urls
     */
    protected function stream(LoopInterface $loop, array &$urls, bool $failConnect = false, int $batchSize = 3, float $batchWait = 0.05): JetstreamStream
    {
        $connections = function () use ($loop, &$urls, $failConnect) {
            return new class ($loop, $urls, $failConnect) extends WebSocketConnection {
                public bool $closed = false;

                public function __construct(LoopInterface $loop, protected array &$urls, protected bool $failConnect)
                {
                    parent::__construct($loop);
                }

                public function connect(string $url, array $subProtocols = []): PromiseInterface
                {
                    $this->urls[] = $url;

                    return $this->failConnect ? reject(new RuntimeException('refused')) : resolve(null);
                }

                public function close(int $code = 1000, string $reason = ''): void
                {
                    $this->closed = true;
                    ($this->onClose)($code, $reason);
                }
            };
        };

        return new JetstreamStream('wss://jetstream.example', ['xyz.statusphere.*'], ['did:plc:alice'], ['commit', 'account'], $batchSize, $batchWait, 0, 1, $connections);
    }

    #[Test]
    public function it_builds_the_subscribe_url_with_every_filter_and_the_callers_cursor(): void
    {
        $stream = new JetstreamStream('wss://jetstream.example/', ['xyz.statusphere.*', 'app.bsky.feed.like'], ['did:plc:alice'], ['commit']);

        $this->assertSame(
            'wss://jetstream.example/xrpc/network.bsky.jetstream.subscribeEvents?cursor=42&collections=xyz.statusphere.*&collections=app.bsky.feed.like&dids=did%3Aplc%3Aalice&kinds=commit',
            $stream->url(42),
        );
        $this->assertStringNotContainsString('cursor=', $stream->url(null), 'No cursor starts at the live tip.');
        $this->assertSame('wss://jetstream.example/xrpc/network.bsky.jetstream.subscribeEvents', (new JetstreamStream('wss://jetstream.example'))->url(0));
    }

    #[Test]
    public function it_refuses_filters_the_server_would_refuse(): void
    {
        foreach ([
            fn () => new JetstreamStream('wss://x', array_map(fn ($i) => "a.b.c{$i}", range(1, 101))),
            fn () => new JetstreamStream('wss://x', [], array_map(fn ($i) => "did:plc:{$i}", range(1, 10001))),
            fn () => new JetstreamStream('wss://x', [], [], ['commit', 'labels']),
            fn () => new JetstreamStream('wss://x', ['a.b.c'], [], ['account']),
        ] as $make) {
            try {
                $make();
                $this->fail('Expected the filter to be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_hands_over_full_batches_and_flushes_a_partial_one_after_the_wait(): void
    {
        $loop = Factory::create();
        $urls = [];
        $batches = [];
        $stream = $this->stream($loop, $urls)->onBatch(function (array $events, ?int $seq) use (&$batches) {
            $batches[] = [array_map(fn ($event) => $event->seq, $events), $seq];
        });

        $loop->futureTick(function () use ($stream) {
            foreach ([10, 11, 12, 13] as $seq) {
                $stream->receive($this->commit($seq));
            }
        });
        $beforeStop = null;
        $loop->addTimer(0.15, function () use (&$batches, &$beforeStop) {
            $beforeStop = count($batches);
        });
        $loop->addTimer(0.2, fn () => $stream->stop());
        $stream->start(fn () => 9);

        $this->assertSame([[[10, 11, 12], 12], [[13], 13]], $batches);
        $this->assertSame(2, $beforeStop, 'The partial batch came after the wait, not only at stop.');
        $this->assertStringContainsString('cursor=9', $urls[0], 'The caller is asked for the cursor.');
    }

    #[Test]
    public function it_passes_advisories_on_and_skips_what_it_cannot_read(): void
    {
        $loop = Factory::create();
        $urls = [];
        $info = [];
        $batches = 0;
        $stream = $this->stream($loop, $urls)
            ->onInfo(function (string $name, ?string $message) use (&$info) {
                $info[] = $name;
            })
            ->onBatch(function () use (&$batches) {
                $batches++;
            });

        $loop->futureTick(function () use ($stream) {
            $stream->receive(json_encode(['$type' => 'message', 'payload' => ['$type' => 'network.bsky.jetstream.subscribeEvents#info', 'name' => 'OutdatedCursor', 'message' => 'clamped']]));
            $stream->receive('not json');
            $stream->receive(json_encode(['$type' => 'message', 'payload' => ['$type' => 'network.bsky.jetstream.subscribeEvents#unknown']]));
        });
        $loop->addTimer(0.1, fn () => $stream->stop());
        $stream->start(fn () => null);

        $this->assertSame(['OutdatedCursor'], $info);
        $this->assertSame(0, $batches);
    }

    #[Test]
    public function a_failed_batch_is_delivered_again_from_the_callers_saved_cursor(): void
    {
        $loop = Factory::create();
        $urls = [];
        $saved = 9;
        $seen = [];
        $errors = [];
        $failOnce = true;
        $stream = $this->stream($loop, $urls)
            ->onBatch(function (array $events, ?int $seq) use (&$saved, &$seen, &$failOnce) {
                $seen[] = $seq;

                if ($failOnce) {
                    $failOnce = false;

                    throw new RuntimeException('database went away');
                }

                $saved = $seq;
            })
            ->onError(function ($e) use (&$errors) {
                $errors[] = $e->getMessage();
            });

        $loop->futureTick(function () use ($stream) {
            foreach ([10, 11, 12] as $seq) {
                $stream->receive($this->commit($seq));
            }
        });
        // After the reconnect (1 s backoff at most), the same events arrive again.
        $loop->addTimer(1.5, function () use ($stream) {
            foreach ([10, 11, 12] as $seq) {
                $stream->receive($this->commit($seq));
            }
        });
        $loop->addTimer(1.8, fn () => $stream->stop());
        $stream->start(function () use (&$saved) {
            return $saved;
        });

        $this->assertSame(['database went away'], $errors);
        $this->assertSame([12, 12], $seen, 'The batch came again after the failure.');
        $this->assertSame(12, $saved);
        $this->assertCount(2, $urls, 'It reconnected.');
        $this->assertStringContainsString('cursor=9', $urls[1], 'From the position the caller had saved.');
    }

    #[Test]
    public function a_reconnect_resumes_from_the_cursor_the_caller_saved_since(): void
    {
        $loop = Factory::create();
        $urls = [];
        $saved = null;
        $connection = null;
        $stream = $this->stream($loop, $urls)->onBatch(function (array $events, ?int $seq) use (&$saved) {
            $saved = $seq;
        });

        $loop->futureTick(function () use ($stream) {
            foreach ([20, 21, 22] as $seq) {
                $stream->receive($this->commit($seq));
            }
        });
        // The socket drops after the batch was saved.
        $loop->addTimer(0.1, function () use ($stream) {
            (fn () => $this->connection->close())->call($stream);
        });
        $loop->addTimer(1.5, fn () => $stream->stop());
        $stream->start(function () use (&$saved) {
            return $saved;
        });

        $this->assertCount(2, $urls);
        $this->assertStringNotContainsString('cursor=', $urls[0], 'Nothing saved yet: the live tip.');
        $this->assertStringContainsString('cursor=22', $urls[1]);
    }

    #[Test]
    public function it_keeps_reconnecting_when_the_server_refuses(): void
    {
        $loop = Factory::create();
        $urls = [];
        $errors = 0;
        $stream = $this->stream($loop, $urls, failConnect: true)->onError(function () use (&$errors) {
            $errors++;
        });

        $loop->addTimer(2.5, fn () => $stream->stop());
        $stream->start(fn () => null);

        $this->assertGreaterThanOrEqual(3, count($urls), 'Backing off at most a second, it tried again and again.');
    }
}
