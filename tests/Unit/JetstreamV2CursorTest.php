<?php

namespace SocialDept\AtpSignals\Tests\Unit;

use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Contracts\CursorStore;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\Services\FirehoseConsumer;
use SocialDept\AtpSignals\Services\JetstreamConsumer;
use SocialDept\AtpSignals\Services\SignalRegistry;
use SocialDept\AtpSignals\SignalServiceProvider;
use SocialDept\AtpSignals\Storage\CursorStoreFactory;
use SocialDept\AtpSignals\Storage\FileCursorStore;

/**
 * Regressions for how the v2 seq cursor is namespaced and resolved.
 *
 * Both cases are silent when wrong: the consumer keeps running and simply
 * reads or writes the wrong position, so they are asserted rather than left
 * to manual observation.
 */
class JetstreamV2CursorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('signal/cursor*.json')) ?: [] as $file) {
            @unlink($file);
        }

        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function the_firehose_keeps_its_own_cursor_while_jetstream_runs_v2()
    {
        config([
            'atp-signals.cursor_storage' => 'file',
            'atp-signals.jetstream_version' => 2,
        ]);

        $jetstream = $this->cursorPath($this->app->make(JetstreamConsumer::class));
        $firehose = $this->cursorPath($this->app->make(FirehoseConsumer::class));

        // Firehose seq numbers and Jetstream v2 seq numbers are different
        // sequence spaces; sharing a slot silently corrupts the position.
        $this->assertSame('cursor-jetstream-v2.json', $jetstream);
        $this->assertSame('cursor.json', $firehose);
    }

    #[Test]
    public function jetstream_v1_leaves_the_default_cursor_slot_alone()
    {
        config([
            'atp-signals.cursor_storage' => 'file',
            'atp-signals.jetstream_version' => 1,
        ]);

        $this->assertSame('cursor.json', $this->cursorPath($this->app->make(JetstreamConsumer::class)));
        $this->assertSame('cursor.json', $this->cursorPath($this->app->make(FirehoseConsumer::class)));
    }

    #[Test]
    public function a_reconnect_resolves_the_same_v1_seed_as_the_initial_start()
    {
        config([
            'atp-signals.cursor_storage' => 'file',
            'atp-signals.jetstream_version' => 2,
        ]);

        $seed = 1786830000000000;

        // A real v1 position exists, and the v2 slot is still empty — the state
        // every reconnect sees until the first event lands.
        CursorStoreFactory::make()->set($seed);
        $v2Store = CursorStoreFactory::make('jetstream-v2');
        $v2Store->clear();

        $consumer = new JetstreamConsumer($v2Store, new SignalRegistry(), Mockery::mock(EventDispatcher::class));

        // start() and the reconnect timer both go through resolveCursor(), so a
        // reconnect before the first event keeps the seeded position instead of
        // silently restarting from live.
        $this->assertSame($seed, $this->invokeMethod($consumer, 'resolveCursor', [null]));
    }

    #[Test]
    public function an_explicit_zero_cursor_still_means_a_fresh_start()
    {
        config(['atp-signals.jetstream_version' => 2]);

        $store = Mockery::mock(CursorStore::class);
        $store->shouldNotReceive('get');

        $consumer = new JetstreamConsumer($store, new SignalRegistry(), Mockery::mock(EventDispatcher::class));

        $this->assertNull($this->invokeMethod($consumer, 'resolveCursor', [0]));
    }

    protected function cursorPath(object $consumer): string
    {
        $store = new \ReflectionProperty($consumer, 'cursorStore');
        $store->setAccessible(true);
        $store = $store->getValue($consumer);

        $this->assertInstanceOf(FileCursorStore::class, $store);

        $path = new \ReflectionProperty($store, 'path');
        $path->setAccessible(true);

        return basename($path->getValue($store));
    }

    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
