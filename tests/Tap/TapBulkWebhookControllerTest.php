<?php

namespace SocialDept\AtpSignals\Tests\Tap;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\SignalServiceProvider;

class TapBulkWebhookControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-signals.tap.enabled', true);
        $app['config']->set('atp-signals.tap.admin_password', 'test-secret');
        $app['config']->set('atp-signals.tap.webhook_path', '/_atp/tap/webhook');
        $app['config']->set('atp-signals.tap.queue_events', false);
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Basic '.base64_encode('admin:test-secret')];
    }

    protected function makeRecordEvent(array $overrides = []): array
    {
        return array_merge([
            'id' => 12345,
            'type' => 'record',
            'record' => [
                'live' => true,
                'rev' => '3kb3fge5lm32x',
                'did' => 'did:plc:test',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test123',
                'action' => 'create',
                'cid' => 'bafyreiabc',
                'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'Hello'],
            ],
        ], $overrides);
    }

    #[Test]    public function it_rejects_unauthenticated_requests()
    {
        $response = $this->postJson('/_atp/tap/webhook/bulk', [
            'events' => [$this->makeRecordEvent()],
        ]);

        $response->assertUnauthorized();
    }

    #[Test]    public function it_rejects_invalid_basic_auth()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:wrong-password'),
        ])->postJson('/_atp/tap/webhook/bulk', [
            'events' => [$this->makeRecordEvent()],
        ]);

        $response->assertUnauthorized();
    }

    #[Test]    public function it_rejects_payload_without_events_key()
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/_atp/tap/webhook/bulk', [
                'data' => 'something',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonFragment(['error' => 'Invalid payload: missing or invalid events array']);
    }

    #[Test]    public function it_accepts_empty_events_array()
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/_atp/tap/webhook/bulk', [
                'events' => [],
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'processed' => 0]);
    }

    #[Test]    public function it_dispatches_multiple_record_events()
    {
        $dispatched = [];

        $this->mock(EventDispatcher::class, function ($mock) use (&$dispatched) {
            $mock->shouldReceive('dispatch')
                ->times(3)
                ->withArgs(function (SignalEvent $event) use (&$dispatched) {
                    $dispatched[] = $event;

                    return true;
                });
        });

        $events = [
            $this->makeRecordEvent(['id' => 1, 'record' => [
                'live' => true, 'rev' => 'r1', 'did' => 'did:plc:user1',
                'collection' => 'app.bsky.feed.post', 'rkey' => 'k1', 'action' => 'create',
                'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'First'],
            ]]),
            $this->makeRecordEvent(['id' => 2, 'record' => [
                'live' => false, 'rev' => 'r2', 'did' => 'did:plc:user2',
                'collection' => 'app.bsky.feed.like', 'rkey' => 'k2', 'action' => 'create',
            ]]),
            $this->makeRecordEvent(['id' => 3, 'record' => [
                'live' => true, 'rev' => 'r3', 'did' => 'did:plc:user3',
                'collection' => 'app.bsky.feed.post', 'rkey' => 'k3', 'action' => 'delete',
            ]]),
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/_atp/tap/webhook/bulk', ['events' => $events]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'processed' => 3]);

        $this->assertCount(3, $dispatched);
        $this->assertEquals('did:plc:user1', $dispatched[0]->did);
        $this->assertFalse($dispatched[0]->backfill);
        $this->assertEquals('did:plc:user2', $dispatched[1]->did);
        $this->assertTrue($dispatched[1]->backfill);
        $this->assertEquals('did:plc:user3', $dispatched[2]->did);
        $this->assertEquals('delete', $dispatched[2]->commit->operation->value);
    }

    #[Test]    public function it_dispatches_identity_events_in_batch()
    {
        $dispatched = [];

        $this->mock(EventDispatcher::class, function ($mock) use (&$dispatched) {
            $mock->shouldReceive('dispatch')
                ->times(2)
                ->withArgs(function (SignalEvent $event) use (&$dispatched) {
                    $dispatched[] = $event;

                    return true;
                });
        });

        $events = [
            $this->makeRecordEvent(),
            [
                'id' => 100,
                'type' => 'identity',
                'identity' => [
                    'did' => 'did:plc:abc123',
                    'handle' => 'test.bsky.social',
                    'isActive' => true,
                    'status' => 'active',
                ],
            ],
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/_atp/tap/webhook/bulk', ['events' => $events]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'processed' => 2]);

        $this->assertTrue($dispatched[0]->isCommit());
        $this->assertTrue($dispatched[1]->isIdentity());
        $this->assertEquals('test.bsky.social', $dispatched[1]->identity->handle);
    }

    #[Test]    public function it_skips_events_without_type()
    {
        $this->mock(EventDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->once();
        });

        $events = [
            ['id' => 1, 'data' => 'no type field'],
            $this->makeRecordEvent(['id' => 2]),
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/_atp/tap/webhook/bulk', ['events' => $events]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'processed' => 1]);
    }

    #[Test]    public function it_continues_processing_after_malformed_event()
    {
        $this->mock(EventDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->once();
        });

        $events = [
            ['id' => 1, 'type' => 'unknown_garbage_type'],
            $this->makeRecordEvent(['id' => 2]),
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/_atp/tap/webhook/bulk', ['events' => $events]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'processed' => 1]);
    }

    #[Test]    public function it_allows_requests_when_no_password_is_configured()
    {
        $this->app['config']->set('atp-signals.tap.admin_password', null);

        $this->mock(EventDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->once();
        });

        $response = $this->postJson('/_atp/tap/webhook/bulk', [
            'events' => [$this->makeRecordEvent()],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'processed' => 1]);
    }

    #[Test]    public function it_returns_correct_processed_count()
    {
        $this->mock(EventDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->times(5);
        });

        $events = array_map(
            fn ($i) => $this->makeRecordEvent(['id' => $i]),
            range(1, 5)
        );

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/_atp/tap/webhook/bulk', ['events' => $events]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'processed' => 5]);
    }

    #[Test]    public function it_rejects_non_array_events_value()
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/_atp/tap/webhook/bulk', [
                'events' => 'not-an-array',
            ]);

        $response->assertUnprocessable();
    }
}
