<?php

namespace SocialDept\AtpSignals\Tests\Unit;

use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Contracts\CursorStore;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\Services\JetstreamConsumer;
use SocialDept\AtpSignals\Services\SignalRegistry;
use SocialDept\AtpSignals\Signals\Signal;
use SocialDept\AtpSignals\Storage\FileCursorStore;
use SocialDept\AtpSignals\Support\JetstreamV2Translator;

class JetstreamV2Test extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_translates_a_v2_commit_payload_with_flat_fields()
    {
        $event = JetstreamV2Translator::toSignalEvent([
            '$type' => 'network.bsky.jetstream.subscribeEvents#commit',
            'did' => 'did:plc:example',
            'seq' => 42,
            'time' => '2026-08-14T12:00:00.000Z',
            'operation' => 'create',
            'collection' => 'app.bsky.feed.post',
            'rkey' => '3kabc',
            'rev' => '3krev',
            'cid' => 'bafyexample',
            'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'hi'],
        ]);

        $this->assertNotNull($event);
        $this->assertTrue($event->isCommit());
        $this->assertSame('did:plc:example', $event->did);
        $this->assertSame(42, $event->seq);
        $this->assertSame('app.bsky.feed.post', $event->getCollection());
        $this->assertSame(SignalCommitOperation::Create, $event->getOperation());
        $this->assertSame('3kabc', $event->commit->rkey);
        $this->assertSame('hi', $event->getRecord()->text);
        $this->assertSame(1786708800000000, $event->timeUs);
    }

    #[Test]
    public function it_translates_sync_identity_and_account_payloads()
    {
        $sync = JetstreamV2Translator::toSignalEvent([
            '$type' => 'network.bsky.jetstream.subscribeEvents#sync',
            'did' => 'did:plc:diverged',
            'seq' => 7,
            'sync' => ['did' => 'did:plc:diverged', 'rev' => '3krev', 'seq' => 99],
        ]);

        $this->assertTrue($sync->isSync());
        $this->assertSame('3krev', $sync->sync->rev);
        $this->assertSame(7, $sync->seq);

        $identity = JetstreamV2Translator::toSignalEvent([
            '$type' => 'network.bsky.jetstream.subscribeEvents#identity',
            'did' => 'did:plc:renamed',
            'seq' => 8,
            'identity' => ['did' => 'did:plc:renamed', 'handle' => 'new.example.com', 'seq' => 99],
        ]);

        $this->assertTrue($identity->isIdentity());
        $this->assertSame('new.example.com', $identity->identity->handle);

        $account = JetstreamV2Translator::toSignalEvent([
            '$type' => 'network.bsky.jetstream.subscribeEvents#account',
            'did' => 'did:plc:gone',
            'seq' => 9,
            'account' => ['did' => 'did:plc:gone', 'active' => false, 'status' => 'deleted', 'seq' => 99],
        ]);

        $this->assertTrue($account->isAccount());
        $this->assertFalse($account->account->active);
        $this->assertSame('deleted', $account->account->status);
    }

    #[Test]
    public function it_translates_flat_payloads_as_a_fallback()
    {
        $account = JetstreamV2Translator::toSignalEvent([
            '$type' => 'network.bsky.jetstream.subscribeEvents#account',
            'did' => 'did:plc:gone',
            'seq' => 9,
            'active' => false,
            'status' => 'deactivated',
        ]);

        $this->assertTrue($account->isAccount());
        $this->assertFalse($account->account->active);
        $this->assertSame('deactivated', $account->account->status);
    }

    #[Test]
    public function it_unwraps_envelopes_and_recognizes_info_frames()
    {
        $frame = [
            'payload' => [
                '$type' => 'network.bsky.jetstream.subscribeEvents#info',
                'name' => 'OutdatedCursor',
                'message' => 'requested cursor exceeded limit',
            ],
        ];

        $payload = JetstreamV2Translator::payload($frame);

        $this->assertTrue(JetstreamV2Translator::isInfo($payload));
        $this->assertNull(JetstreamV2Translator::toSignalEvent($payload));

        $bare = ['$type' => 'network.bsky.jetstream.subscribeEvents#sync', 'did' => 'did:plc:x', 'seq' => 1];
        $this->assertSame($bare, JetstreamV2Translator::payload($bare));
    }

    #[Test]
    public function it_rejects_payloads_missing_required_fields()
    {
        $this->assertNull(JetstreamV2Translator::toSignalEvent([
            '$type' => 'network.bsky.jetstream.subscribeEvents#commit',
            'did' => 'did:plc:example',
            'seq' => 1,
        ]));

        $this->assertNull(JetstreamV2Translator::toSignalEvent([
            '$type' => 'network.bsky.jetstream.subscribeEvents#unknown',
            'did' => 'did:plc:example',
        ]));
    }

    #[Test]
    public function it_builds_a_v2_url_with_collections_kinds_and_cursor()
    {
        config(['atp-signals.jetstream_version' => 2]);

        $signal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit', 'sync'];
            }

            public function collections(): ?array
            {
                return ['site.standard.document', 'blog.pckt.*'];
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $registry = new SignalRegistry();
        $registry->register($signal::class);

        $consumer = $this->createConsumer($registry);
        $url = $this->invokeMethod($consumer, 'buildWebSocketUrl', [123456]);

        $this->assertStringStartsWith('wss://jetstream.us-west.bsky.network/xrpc/network.bsky.jetstream.subscribeEvents?', $url);
        $this->assertStringContainsString('cursor=123456', $url);
        $this->assertStringContainsString('collections=site.standard.document', $url);
        $this->assertStringContainsString('collections=blog.pckt.*', $url);
        $this->assertStringContainsString('kinds=commit', $url);
        $this->assertStringContainsString('kinds=sync', $url);
        $this->assertStringNotContainsString('wantedCollections', $url);
    }

    #[Test]
    public function it_omits_the_collections_filter_when_no_signal_handles_commits()
    {
        config(['atp-signals.jetstream_version' => 2]);

        $signal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['sync'];
            }

            public function collections(): ?array
            {
                return ['site.standard.document'];
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $registry = new SignalRegistry();
        $registry->register($signal::class);

        $consumer = $this->createConsumer($registry);
        $url = $this->invokeMethod($consumer, 'buildWebSocketUrl', [null]);

        $this->assertStringNotContainsString('collections=', $url);
        $this->assertStringContainsString('kinds=sync', $url);
    }

    #[Test]
    public function it_seeds_a_fresh_v2_cursor_from_the_v1_time_us_position()
    {
        config([
            'atp-signals.jetstream_version' => 2,
            'atp-signals.cursor_storage' => 'file',
            'atp-signals.cursor_config.file.path' => sys_get_temp_dir().'/atp-signals-v2-seed-test/cursor.json',
        ]);

        $v1Store = new FileCursorStore();
        $v1Store->set(1786363200000000);

        $consumer = $this->createConsumer(new SignalRegistry());
        $seed = $this->invokeMethod($consumer, 'seedCursorFromV1');

        $this->assertSame(1786363200000000, $seed);

        $v1Store->clear();
    }

    protected function createConsumer(SignalRegistry $registry): JetstreamConsumer
    {
        $cursorStore = Mockery::mock(CursorStore::class);
        $eventDispatcher = Mockery::mock(EventDispatcher::class);

        return new JetstreamConsumer($cursorStore, $registry, $eventDispatcher);
    }

    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
