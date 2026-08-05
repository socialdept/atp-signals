<?php

namespace SocialDept\AtpSignals\Tests\Obelisk;

use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Jobs\ProcessObeliskBatchJob;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\SignalServiceProvider;

class ObeliskWebhookControllerTest extends TestCase
{
    protected const SECRET = 'test-webhook-secret';

    protected const PATH = '/_atp/obelisk/webhook';

    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-signals.obelisk.enabled', true);
        $app['config']->set('atp-signals.obelisk.webhook_secret', self::SECRET);
        $app['config']->set('atp-signals.obelisk.webhook_path', self::PATH);
        $app['config']->set('atp-signals.obelisk.queue_events', false);
    }

    protected function makeEvent(array $overrides = []): array
    {
        return array_merge([
            'cursor' => '1',
            'uri' => 'at://did:plc:test/site.standard.document/3lab',
            'did' => 'did:plc:test',
            'collection' => 'site.standard.document',
            'rkey' => '3lab',
            'action' => 'create',
            'cid' => 'bafyreiabc',
            'rev' => '3kb3fge5lm32x',
            'live' => true,
            'createdAt' => '2026-08-04T12:00:00.000Z',
            'record' => ['$type' => 'site.standard.document', 'title' => 'Hello'],
        ], $overrides);
    }

    protected function payload(array $events): string
    {
        return json_encode(['subscription' => 'test-app', 'cursor' => '1', 'events' => $events]);
    }

    /**
     * Post a raw body with a valid signature unless one is supplied.
     */
    protected function deliver(string $body, ?string $signature = null)
    {
        return $this->call(
            'POST',
            self::PATH,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_OBELISK_SIGNATURE' => $signature ?? 'sha256='.hash_hmac('sha256', $body, self::SECRET),
                'HTTP_X_OBELISK_SUBSCRIPTION' => 'test-app',
            ],
            $body,
        );
    }

    #[Test]    public function it_rejects_a_delivery_with_no_signature()
    {
        $this->deliver($this->payload([$this->makeEvent()]), '')->assertUnauthorized();
    }

    #[Test]    public function it_rejects_a_delivery_with_a_wrong_signature()
    {
        $this->deliver($this->payload([$this->makeEvent()]), 'sha256=deadbeef')->assertUnauthorized();
    }

    #[Test]    public function it_rejects_a_signature_computed_over_a_different_body()
    {
        $body = $this->payload([$this->makeEvent()]);
        $otherSignature = 'sha256='.hash_hmac('sha256', $this->payload([]), self::SECRET);

        $this->deliver($body, $otherSignature)->assertUnauthorized();
    }

    #[Test]    public function it_fails_closed_when_no_secret_is_configured()
    {
        config()->set('atp-signals.obelisk.webhook_secret', null);

        $body = $this->payload([$this->makeEvent()]);

        $this->deliver($body, 'sha256='.hash_hmac('sha256', $body, self::SECRET))->assertUnauthorized();
    }

    #[Test]    public function it_accepts_a_valid_signature()
    {
        $this->deliver($this->payload([$this->makeEvent()]))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'processed' => 1]);
    }

    #[Test]    public function it_skips_verification_when_explicitly_disabled()
    {
        config()->set('atp-signals.obelisk.verify_signature', false);
        config()->set('atp-signals.obelisk.webhook_secret', null);

        $this->deliver($this->payload([$this->makeEvent()]), 'sha256=nonsense')->assertOk();
    }

    #[Test]    public function it_rejects_a_payload_without_an_events_array()
    {
        $body = json_encode(['subscription' => 'test-app', 'cursor' => '1']);

        $this->deliver($body)
            ->assertUnprocessable()
            ->assertJsonFragment(['error' => 'Invalid payload: missing or invalid events array']);
    }

    #[Test]    public function it_accepts_an_empty_batch()
    {
        $this->deliver($this->payload([]))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'processed' => 0]);
    }

    #[Test]    public function it_dispatches_events_to_signals_in_cursor_order()
    {
        $collector = new class () extends EventDispatcher {
            /** @var array<int, string> */
            public array $seen = [];

            public function __construct()
            {
            }

            public function dispatch(SignalEvent $event): void
            {
                $this->seen[] = $event->cursor.':'.$event->commit->operation->value;
            }
        };

        $this->app->instance(EventDispatcher::class, $collector);

        $body = $this->payload([
            $this->makeEvent(['cursor' => '1', 'action' => 'create']),
            $this->makeEvent(['cursor' => '2', 'action' => 'update']),
            $this->makeEvent(['cursor' => '3', 'action' => 'delete', 'record' => null]),
        ]);

        $this->deliver($body)->assertOk()->assertJson(['processed' => 3]);

        $this->assertSame(['1:create', '2:update', '3:delete'], $collector->seen);
    }

    #[Test]    public function it_skips_a_malformed_event_without_dropping_the_batch()
    {
        $body = $this->payload([
            $this->makeEvent(['cursor' => '1']),
            ['cursor' => '2', 'live' => true], // no did/collection/rkey/action
            $this->makeEvent(['cursor' => '3']),
        ]);

        $this->deliver($body)->assertOk()->assertJson(['processed' => 2]);
    }

    #[Test]    public function it_queues_the_batch_as_one_job_when_queueing_is_enabled()
    {
        config()->set('atp-signals.obelisk.queue_events', true);
        config()->set('atp-signals.obelisk.queue_name', 'obelisk');
        Queue::fake();

        $this->deliver($this->payload([$this->makeEvent(['cursor' => '1']), $this->makeEvent(['cursor' => '2'])]))
            ->assertOk()
            ->assertJson(['processed' => 2]);

        Queue::assertPushedOn('obelisk', ProcessObeliskBatchJob::class);
        Queue::assertPushed(ProcessObeliskBatchJob::class, 1);
    }

    #[Test]    public function it_refuses_with_503_once_the_queue_is_saturated()
    {
        config()->set('atp-signals.obelisk.queue_events', true);
        config()->set('atp-signals.obelisk.max_queue_depth', 3);
        Queue::fake();

        // Fill to the ceiling for real — each accepted delivery queues one job.
        foreach (range(1, 3) as $i) {
            $this->deliver($this->payload([$this->makeEvent(['cursor' => (string) $i])]))->assertOk();
        }

        // Accepting is cheap and handling is not, so past the ceiling we stop
        // taking work. The archive holds its cursor and re-sends this batch.
        $this->deliver($this->payload([$this->makeEvent(['cursor' => '4'])]))
            ->assertStatus(503)
            ->assertJsonFragment(['error' => 'Queue saturated'])
            ->assertHeader('Retry-After', '60');

        // Nothing extra was queued by the refused delivery.
        Queue::assertPushed(ProcessObeliskBatchJob::class, 3);
    }

    #[Test]    public function it_accepts_while_the_queue_is_below_the_ceiling()
    {
        config()->set('atp-signals.obelisk.queue_events', true);
        config()->set('atp-signals.obelisk.max_queue_depth', 3);
        Queue::fake();

        foreach (range(1, 2) as $i) {
            $this->deliver($this->payload([$this->makeEvent(['cursor' => (string) $i])]))->assertOk();
        }

        Queue::assertPushed(ProcessObeliskBatchJob::class, 2);
    }

    #[Test]    public function the_brake_is_off_when_handling_inline()
    {
        // Inline handling makes the request itself the backpressure — the
        // archive waits for the response — so the depth check is skipped.
        config()->set('atp-signals.obelisk.queue_events', false);
        config()->set('atp-signals.obelisk.max_queue_depth', 1);

        $this->deliver($this->payload([$this->makeEvent()]))->assertOk();
    }

    #[Test]    public function a_zero_ceiling_disables_the_brake()
    {
        config()->set('atp-signals.obelisk.queue_events', true);
        config()->set('atp-signals.obelisk.max_queue_depth', 0);
        Queue::fake();

        foreach (range(1, 5) as $i) {
            $this->deliver($this->payload([$this->makeEvent(['cursor' => (string) $i])]))->assertOk();
        }
    }

    #[Test]    public function it_returns_500_when_the_batch_cannot_be_accepted()
    {
        // A dispatcher that blows up stands in for a queue that will not take the job:
        // the endpoint must not answer 200, or Obelisk advances past unhandled events.
        $this->app->instance(EventDispatcher::class, new class () extends EventDispatcher {
            public function __construct()
            {
            }

            public function dispatch(SignalEvent $event): void
            {
                throw new \RuntimeException('queue unavailable');
            }
        });

        $this->deliver($this->payload([$this->makeEvent()]))
            ->assertStatus(500)
            ->assertJsonFragment(['error' => 'Failed to accept batch']);
    }
}
