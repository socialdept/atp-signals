<?php

namespace SocialDept\AtpSignals\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use SocialDept\AtpCbor\Core\CBOR;
use SocialDept\AtpSignals\Labels\LabelStream;
use SocialDept\AtpSignals\Support\WebSocketConnection;

/**
 * A labeler's subscribeLabels stream: CBOR frames decoded into labels, sig
 * kept for the caller, advisories and error frames, batched by seq.
 */
class LabelStreamTest extends TestCase
{
    protected const SUBJECT = 'did:plc:eob75vcjtmbaef2tn4evc4sl';

    protected function frame(array $header, array $body): string
    {
        return CBOR::encode($header).CBOR::encode($body);
    }

    protected function labels(int $seq, int $count): string
    {
        $labels = [];

        for ($i = 0; $i < $count; $i++) {
            $labels[] = ['ver' => 1, 'src' => 'did:plc:labeler', 'uri' => self::SUBJECT, 'val' => "value{$i}", 'cts' => '2026-10-21T12:00:00.000Z', 'sig' => str_repeat("\x01", 64)];
        }

        return $this->frame(['op' => 1, 't' => '#labels'], ['seq' => $seq, 'labels' => $labels]);
    }

    protected function stream(LoopInterface $loop, array &$urls): LabelStream
    {
        $connections = function () use ($loop, &$urls) {
            return new class ($loop, $urls) extends WebSocketConnection {
                public function __construct(LoopInterface $loop, protected array &$urls)
                {
                    parent::__construct($loop);
                }

                public function connect(string $url, array $subProtocols = []): PromiseInterface
                {
                    $this->urls[] = $url;

                    return resolve(null);
                }
            };
        };

        return new LabelStream('https://mod.example/', 3, 0.05, 0, 1, $connections);
    }

    #[Test]
    public function it_subscribes_over_websockets_from_the_callers_cursor(): void
    {
        $stream = new LabelStream('https://mod.example/');

        $this->assertSame('wss://mod.example/xrpc/com.atproto.label.subscribeLabels?cursor=44', $stream->url(44));
        $this->assertSame('wss://mod.example/xrpc/com.atproto.label.subscribeLabels', $stream->url(null), 'No cursor starts at the current label.');
        $this->assertSame('ws://localhost:2583/xrpc/com.atproto.label.subscribeLabels', (new LabelStream('http://localhost:2583'))->url(0));
    }

    #[Test]
    public function it_batches_labels_across_frames_with_the_last_seq_and_keeps_signatures(): void
    {
        $loop = Factory::create();
        $urls = [];
        $batches = [];
        $stream = $this->stream($loop, $urls)->onBatch(function (array $labels, ?int $seq) use (&$batches) {
            $batches[] = [array_column($labels, 'val'), $seq, strlen($labels[0]['sig'])];
        });

        $loop->futureTick(function () use ($stream) {
            $stream->receive($this->labels(7, 2));
            $stream->receive($this->labels(8, 2));
        });
        $loop->addTimer(0.2, fn () => $stream->stop());
        $stream->start(fn () => 6);

        $this->assertSame([[['value0', 'value1', 'value0', 'value1'], 8, 64]], $batches, 'Two frames of two make one batch past the size of three.');
        $this->assertStringContainsString('cursor=6', $urls[0]);
    }

    #[Test]
    public function advisories_go_to_info_and_error_frames_to_errors(): void
    {
        $loop = Factory::create();
        $urls = [];
        $info = [];
        $errors = [];
        $stream = $this->stream($loop, $urls)
            ->onInfo(function (string $name, ?string $message) use (&$info) {
                $info[] = [$name, $message];
            })
            ->onError(function ($e) use (&$errors) {
                $errors[] = $e->getMessage();
            });

        $loop->futureTick(function () use ($stream) {
            $stream->receive($this->frame(['op' => 1, 't' => '#info'], ['name' => 'OutdatedCursor', 'message' => 'too old']));
            $stream->receive($this->frame(['op' => -1], ['error' => 'FutureCursor', 'message' => 'ahead']));
            $stream->receive('not cbor at all');
        });
        $loop->addTimer(0.1, fn () => $stream->stop());
        $stream->start(fn () => null);

        $this->assertSame([['OutdatedCursor', 'too old']], $info);
        $this->assertSame('Labeler error FutureCursor: ahead', $errors[0]);
    }
}
