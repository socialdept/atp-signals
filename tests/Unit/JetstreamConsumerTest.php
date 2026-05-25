<?php

namespace SocialDept\AtpSignals\Tests\Unit;

use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Contracts\CursorStore;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\Services\JetstreamConsumer;
use SocialDept\AtpSignals\Services\SignalRegistry;
use SocialDept\AtpSignals\Signals\Signal;

class JetstreamConsumerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]    public function it_builds_url_without_collection_filter_when_signal_wants_all_collections()
    {
        $signal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return null; // Wants all collections
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

        // Should NOT have wantedCollections parameter
        $this->assertStringNotContainsString('wantedCollections', $url);
        $this->assertEquals('wss://jetstream2.us-east.bsky.network/subscribe', $url);
    }

    #[Test]    public function it_builds_url_with_collection_filter_when_signal_specifies_collections()
    {
        $signal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return ['app.bsky.feed.post'];
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

        $this->assertStringContainsString('wantedCollections=app.bsky.feed.post', $url);
    }

    #[Test]    public function it_builds_url_with_third_party_collection()
    {
        $signal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
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

        $this->assertStringContainsString('wantedCollections=site.standard.document', $url);
    }

    #[Test]    public function it_omits_collection_filter_when_any_signal_wants_all_collections()
    {
        $specificSignal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return ['app.bsky.feed.post'];
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $allCollectionsSignal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return null; // Wants all collections
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $registry = new SignalRegistry();
        $registry->register($specificSignal::class);
        $registry->register($allCollectionsSignal::class);

        $consumer = $this->createConsumer($registry);
        $url = $this->invokeMethod($consumer, 'buildWebSocketUrl', [null]);

        // Should NOT have wantedCollections because one signal wants all
        $this->assertStringNotContainsString('wantedCollections', $url);
    }

    #[Test]    public function it_combines_collections_from_multiple_signals()
    {
        $postSignal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return ['app.bsky.feed.post'];
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $likeSignal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return ['app.bsky.feed.like'];
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $registry = new SignalRegistry();
        $registry->register($postSignal::class);
        $registry->register($likeSignal::class);

        $consumer = $this->createConsumer($registry);
        $url = $this->invokeMethod($consumer, 'buildWebSocketUrl', [null]);

        $this->assertStringContainsString('wantedCollections=app.bsky.feed.post', $url);
        $this->assertStringContainsString('wantedCollections=app.bsky.feed.like', $url);
    }

    #[Test]    public function it_preserves_wildcard_asterisk_in_collection_filter()
    {
        $signal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return ['app.bsky.feed.*'];
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

        // Should have literal * not %2A
        $this->assertStringContainsString('wantedCollections=app.bsky.feed.*', $url);
        $this->assertStringNotContainsString('%2A', $url);
    }

    #[Test]    public function it_includes_cursor_in_url_when_provided()
    {
        $signal = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return ['app.bsky.feed.post'];
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $registry = new SignalRegistry();
        $registry->register($signal::class);

        $consumer = $this->createConsumer($registry);
        $url = $this->invokeMethod($consumer, 'buildWebSocketUrl', [1234567890]);

        $this->assertStringContainsString('cursor=1234567890', $url);
        $this->assertStringContainsString('wantedCollections=app.bsky.feed.post', $url);
    }

    #[Test]    public function it_deduplicates_collections_from_multiple_signals()
    {
        $signal1 = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return ['app.bsky.feed.post', 'app.bsky.feed.like'];
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $signal2 = new class () extends Signal {
            public function eventTypes(): array
            {
                return ['commit'];
            }

            public function collections(): ?array
            {
                return ['app.bsky.feed.post', 'app.bsky.graph.follow'];
            }

            public function handle(SignalEvent $event): void
            {
                //
            }
        };

        $registry = new SignalRegistry();
        $registry->register($signal1::class);
        $registry->register($signal2::class);

        $consumer = $this->createConsumer($registry);
        $url = $this->invokeMethod($consumer, 'buildWebSocketUrl', [null]);

        // Count occurrences of app.bsky.feed.post - should only appear once
        $count = substr_count($url, 'wantedCollections=app.bsky.feed.post');
        $this->assertEquals(1, $count);

        // But all unique collections should be present
        $this->assertStringContainsString('app.bsky.feed.like', $url);
        $this->assertStringContainsString('app.bsky.graph.follow', $url);
    }

    protected function createConsumer(SignalRegistry $registry): JetstreamConsumer
    {
        $cursorStore = Mockery::mock(CursorStore::class);
        $eventDispatcher = Mockery::mock(EventDispatcher::class);

        return new JetstreamConsumer($cursorStore, $registry, $eventDispatcher);
    }

    /**
     * Call protected/private method of a class.
     */
    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
