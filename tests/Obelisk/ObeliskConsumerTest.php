<?php

namespace SocialDept\AtpSignals\Tests\Obelisk;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Obelisk\ObeliskBatchProcessor;
use SocialDept\AtpSignals\Obelisk\ObeliskClient;
use SocialDept\AtpSignals\Obelisk\ObeliskConsumer;
use SocialDept\AtpSignals\Obelisk\ObeliskEventNormalizer;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\SignalServiceProvider;
use SocialDept\AtpSignals\Storage\FileCursorStore;

class ObeliskConsumerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-signals.obelisk.base_url', 'http://obelisk.test');
        $app['config']->set('atp-signals.cursor_storage', 'file');
        $app['config']->set('atp-signals.cursor_config.file.path', $this->cursorPath());
    }

    protected function cursorPath(): string
    {
        return sys_get_temp_dir().'/atp-signals-test/cursor.json';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The consumer namespaces its store, so clear the suffixed file too.
        foreach ([$this->cursorPath(), sys_get_temp_dir().'/atp-signals-test/cursor-obelisk.json'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    protected function event(int $cursor, array $overrides = []): array
    {
        return array_merge([
            'cursor' => (string) $cursor,
            'uri' => "at://did:plc:test/site.standard.document/r{$cursor}",
            'did' => 'did:plc:test',
            'collection' => 'site.standard.document',
            'rkey' => "r{$cursor}",
            'action' => 'create',
            'cid' => 'bafyreiabc',
            'rev' => '3kb3fge5lm32x',
            'live' => true,
            'createdAt' => '2026-08-04T12:00:00.000Z',
            'record' => ['$type' => 'site.standard.document', 'title' => 'Hello'],
        ], $overrides);
    }

    /**
     * @return array{0: ObeliskConsumer, 1: object}
     */
    protected function makeConsumer(): array
    {
        $collector = new class () extends EventDispatcher {
            /** @var array<int, string> */
            public array $seen = [];

            public function __construct()
            {
            }

            public function dispatch(SignalEvent $event): void
            {
                $this->seen[] = (string) $event->cursor;
            }
        };

        $consumer = new ObeliskConsumer(
            $this->app->make(ObeliskClient::class),
            new ObeliskBatchProcessor($collector, new ObeliskEventNormalizer()),
            new FileCursorStore('obelisk'),
        );

        return [$consumer, $collector];
    }

    #[Test]    public function it_processes_a_page_and_persists_the_cursor()
    {
        Http::fake([
            '*' => Http::response(['events' => [$this->event(1), $this->event(2)], 'cursor' => '2']),
        ]);

        [$consumer, $collector] = $this->makeConsumer();

        $this->assertSame(2, $consumer->pullOnce(0));
        $this->assertSame(['1', '2'], $collector->seen);
        $this->assertSame(2, (new FileCursorStore('obelisk'))->get());
    }

    #[Test]    public function it_resumes_from_the_stored_cursor()
    {
        (new FileCursorStore('obelisk'))->set(41);

        Http::fake(['*' => Http::response(['events' => [], 'cursor' => null])]);

        [$consumer] = $this->makeConsumer();
        $consumer->pullOnce();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'cursor=41'));
    }

    #[Test]    public function it_leaves_the_cursor_alone_on_an_empty_page()
    {
        (new FileCursorStore('obelisk'))->set(10);

        Http::fake(['*' => Http::response(['events' => [], 'cursor' => null])]);

        [$consumer] = $this->makeConsumer();

        $this->assertSame(0, $consumer->pullOnce());
        $this->assertSame(10, (new FileCursorStore('obelisk'))->get());
    }

    #[Test]    public function it_reports_failure_and_holds_the_cursor_when_the_archive_is_unreachable()
    {
        (new FileCursorStore('obelisk'))->set(5);

        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        [$consumer] = $this->makeConsumer();

        $this->assertNull($consumer->pullOnce());
        $this->assertSame(5, (new FileCursorStore('obelisk'))->get());
    }

    #[Test]    public function it_drains_pages_until_a_short_one()
    {
        config()->set('atp-signals.obelisk.pull.limit', 2);

        Http::fakeSequence()
            ->push(['events' => [$this->event(1), $this->event(2)], 'cursor' => '2'])
            ->push(['events' => [$this->event(3), $this->event(4)], 'cursor' => '4'])
            ->push(['events' => [$this->event(5)], 'cursor' => '5']);

        [$consumer, $collector] = $this->makeConsumer();

        $this->assertSame(5, $consumer->drain(0));
        $this->assertSame(['1', '2', '3', '4', '5'], $collector->seen);
        $this->assertSame(5, (new FileCursorStore('obelisk'))->get());
    }

    #[Test]    public function it_passes_the_configured_collection_filter()
    {
        config()->set('atp-signals.obelisk.pull.collection', 'site.standard.document');

        Http::fake(['*' => Http::response(['events' => [], 'cursor' => null])]);

        [$consumer] = $this->makeConsumer();
        $consumer->pullOnce(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'collection=site.standard.document'));
    }
}
