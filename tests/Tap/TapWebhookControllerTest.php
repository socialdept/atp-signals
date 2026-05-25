<?php

namespace SocialDept\AtpSignals\Tests\Tap;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\SignalServiceProvider;

class TapWebhookControllerTest extends TestCase
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

    #[Test]    public function it_rejects_unauthenticated_requests()
    {
        $response = $this->postJson('/_atp/tap/webhook', [
            'id' => 1,
            'type' => 'record',
        ]);

        $response->assertUnauthorized();
    }

    #[Test]    public function it_rejects_invalid_basic_auth()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:wrong-password'),
        ])->postJson('/_atp/tap/webhook', [
            'id' => 1,
            'type' => 'record',
        ]);

        $response->assertUnauthorized();
    }

    #[Test]    public function it_accepts_valid_basic_auth()
    {
        $this->mock(EventDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->once();
        });

        $response = $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:test-secret'),
        ])->postJson('/_atp/tap/webhook', [
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
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
    }

    #[Test]    public function it_rejects_payload_without_type()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:test-secret'),
        ])->postJson('/_atp/tap/webhook', [
            'id' => 1,
        ]);

        $response->assertUnprocessable();
    }

    #[Test]    public function it_dispatches_record_event_to_event_dispatcher()
    {
        $dispatched = null;

        $this->mock(EventDispatcher::class, function ($mock) use (&$dispatched) {
            $mock->shouldReceive('dispatch')
                ->once()
                ->withArgs(function (SignalEvent $event) use (&$dispatched) {
                    $dispatched = $event;

                    return true;
                });
        });

        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:test-secret'),
        ])->postJson('/_atp/tap/webhook', [
            'id' => 12345,
            'type' => 'record',
            'record' => [
                'live' => false,
                'rev' => 'rev123',
                'did' => 'did:plc:abc123',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'rkey456',
                'action' => 'create',
                'cid' => 'bafyreiabc',
                'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'Hello'],
            ],
        ]);

        $this->assertNotNull($dispatched);
        $this->assertEquals('did:plc:abc123', $dispatched->did);
        $this->assertEquals('commit', $dispatched->kind);
        $this->assertTrue($dispatched->backfill);
        $this->assertEquals('app.bsky.feed.post', $dispatched->commit->collection);
        $this->assertEquals('create', $dispatched->commit->operation->value);
        $this->assertEquals('Hello', $dispatched->commit->record->text);
    }

    #[Test]    public function it_allows_requests_when_no_password_is_configured()
    {
        $this->app['config']->set('atp-signals.tap.admin_password', null);

        $this->mock(EventDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->once();
        });

        $response = $this->postJson('/_atp/tap/webhook', [
            'id' => 12345,
            'type' => 'record',
            'record' => [
                'live' => true,
                'rev' => 'abc',
                'did' => 'did:plc:test',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test',
                'action' => 'create',
            ],
        ]);

        $response->assertOk();
    }

    #[Test]    public function it_dispatches_identity_event()
    {
        $dispatched = null;

        $this->mock(EventDispatcher::class, function ($mock) use (&$dispatched) {
            $mock->shouldReceive('dispatch')
                ->once()
                ->withArgs(function (SignalEvent $event) use (&$dispatched) {
                    $dispatched = $event;

                    return true;
                });
        });

        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:test-secret'),
        ])->postJson('/_atp/tap/webhook', [
            'id' => 12346,
            'type' => 'identity',
            'identity' => [
                'did' => 'did:plc:abc123',
                'handle' => 'test.bsky.social',
                'isActive' => true,
                'status' => 'active',
            ],
        ]);

        $this->assertNotNull($dispatched);
        $this->assertTrue($dispatched->isIdentity());
        $this->assertEquals('test.bsky.social', $dispatched->identity->handle);
        $this->assertTrue($dispatched->account->active);
    }

    #[Test]    public function live_true_maps_to_backfill_false()
    {
        $dispatched = null;

        $this->mock(EventDispatcher::class, function ($mock) use (&$dispatched) {
            $mock->shouldReceive('dispatch')
                ->once()
                ->withArgs(function (SignalEvent $event) use (&$dispatched) {
                    $dispatched = $event;

                    return true;
                });
        });

        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:test-secret'),
        ])->postJson('/_atp/tap/webhook', [
            'id' => 12347,
            'type' => 'record',
            'record' => [
                'live' => true,
                'rev' => 'abc',
                'did' => 'did:plc:test',
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test',
                'action' => 'create',
            ],
        ]);

        $this->assertNotNull($dispatched);
        $this->assertFalse($dispatched->backfill);
    }
}
