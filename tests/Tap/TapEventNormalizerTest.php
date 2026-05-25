<?php

namespace SocialDept\AtpSignals\Tests\Tap;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Tap\TapEventNormalizer;

class TapEventNormalizerTest extends TestCase
{
    protected TapEventNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new TapEventNormalizer();
    }

    #[Test]    public function it_normalizes_a_record_create_event()
    {
        $data = [
            'id' => 12345,
            'type' => 'record',
            'record' => [
                'live' => true,
                'rev' => '3kb3fge5lm32x',
                'did' => 'did:plc:abc123',
                'collection' => 'app.bsky.feed.post',
                'rkey' => '3jui7c2abc',
                'action' => 'create',
                'cid' => 'bafyreiabc123',
                'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'Hello world'],
            ],
        ];

        $event = $this->normalizer->normalize($data);

        $this->assertInstanceOf(SignalEvent::class, $event);
        $this->assertEquals('did:plc:abc123', $event->did);
        $this->assertEquals('commit', $event->kind);
        $this->assertFalse($event->backfill);
        $this->assertTrue($event->isCommit());
        $this->assertNotNull($event->commit);
        $this->assertEquals('3kb3fge5lm32x', $event->commit->rev);
        $this->assertEquals(SignalCommitOperation::Create, $event->commit->operation);
        $this->assertEquals('app.bsky.feed.post', $event->commit->collection);
        $this->assertEquals('3jui7c2abc', $event->commit->rkey);
        $this->assertEquals('Hello world', $event->commit->record->text);
        $this->assertEquals('bafyreiabc123', $event->commit->cid);
    }

    #[Test]    public function it_normalizes_a_record_update_event()
    {
        $data = [
            'id' => 12346,
            'type' => 'record',
            'record' => [
                'live' => true,
                'rev' => 'def456rev',
                'did' => 'did:plc:abc123',
                'collection' => 'app.bsky.actor.profile',
                'rkey' => 'self',
                'action' => 'update',
                'cid' => 'bafyreidef456',
                'record' => ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Test'],
            ],
        ];

        $event = $this->normalizer->normalize($data);

        $this->assertTrue($event->commit->isUpdate());
        $this->assertEquals('app.bsky.actor.profile', $event->commit->collection);
    }

    #[Test]    public function it_normalizes_a_record_delete_event()
    {
        $data = [
            'id' => 12347,
            'type' => 'record',
            'record' => [
                'live' => true,
                'rev' => 'ghi789rev',
                'did' => 'did:plc:abc123',
                'collection' => 'app.bsky.feed.post',
                'rkey' => '3jui7c2abc',
                'action' => 'delete',
            ],
        ];

        $event = $this->normalizer->normalize($data);

        $this->assertTrue($event->commit->isDelete());
        $this->assertNull($event->commit->record);
        $this->assertNull($event->commit->cid);
    }

    #[Test]    public function it_normalizes_an_identity_event()
    {
        $data = [
            'id' => 12348,
            'type' => 'identity',
            'identity' => [
                'did' => 'did:plc:abc123',
                'handle' => 'test.bsky.social',
                'isActive' => true,
                'status' => 'active',
            ],
        ];

        $event = $this->normalizer->normalize($data);

        $this->assertTrue($event->isIdentity());
        $this->assertEquals('did:plc:abc123', $event->did);
        $this->assertNotNull($event->identity);
        $this->assertEquals('did:plc:abc123', $event->identity->did);
        $this->assertEquals('test.bsky.social', $event->identity->handle);
        $this->assertNotNull($event->account);
        $this->assertTrue($event->account->active);
        $this->assertEquals('active', $event->account->status);
    }

    #[Test]    public function it_sets_backfill_true_when_live_is_false()
    {
        $data = [
            'id' => 12349,
            'type' => 'record',
            'record' => [
                'live' => false,
                'rev' => 'abc',
                'did' => 'did:plc:abc123',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test',
                'action' => 'create',
            ],
        ];

        $event = $this->normalizer->normalize($data);
        $this->assertTrue($event->backfill);
    }

    #[Test]    public function it_sets_backfill_false_when_live_is_true()
    {
        $data = [
            'id' => 12350,
            'type' => 'record',
            'record' => [
                'live' => true,
                'rev' => 'abc',
                'did' => 'did:plc:abc123',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test',
                'action' => 'create',
            ],
        ];

        $event = $this->normalizer->normalize($data);
        $this->assertFalse($event->backfill);
    }

    #[Test]    public function it_defaults_backfill_to_false_when_live_is_missing()
    {
        $data = [
            'id' => 12351,
            'type' => 'record',
            'record' => [
                'rev' => 'abc',
                'did' => 'did:plc:abc123',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test',
                'action' => 'create',
            ],
        ];

        $event = $this->normalizer->normalize($data);
        $this->assertFalse($event->backfill);
    }

    #[Test]    public function normalized_record_event_serializes_with_backfill()
    {
        $data = [
            'id' => 12352,
            'type' => 'record',
            'record' => [
                'live' => false,
                'rev' => 'abc',
                'did' => 'did:plc:abc123',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test',
                'action' => 'create',
            ],
        ];

        $event = $this->normalizer->normalize($data);
        $array = $event->toArray();

        $this->assertTrue($array['backfill']);
        $this->assertEquals('did:plc:abc123', $array['did']);
        $this->assertEquals('commit', $array['kind']);
    }

    #[Test]    public function it_throws_on_unknown_event_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Tap event type: unknown');

        $this->normalizer->normalize([
            'id' => 99999,
            'type' => 'unknown',
        ]);
    }

    #[Test]    public function it_extracts_did_from_record_payload()
    {
        $data = [
            'id' => 12353,
            'type' => 'record',
            'record' => [
                'live' => true,
                'rev' => 'abc',
                'did' => 'did:plc:specific-did',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test',
                'action' => 'create',
            ],
        ];

        $event = $this->normalizer->normalize($data);
        $this->assertEquals('did:plc:specific-did', $event->did);
    }
}
