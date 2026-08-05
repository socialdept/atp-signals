<?php

namespace SocialDept\AtpSignals\Tests\Unit;

use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Services\SignalManager;
use SocialDept\AtpSignals\SignalServiceProvider;

class SignalManagerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    #[Test]    public function it_throws_for_unknown_modes()
    {
        config()->set('atp-signals.mode', 'mystery');

        $manager = $this->app->make(SignalManager::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Must be 'jetstream', 'firehose', or 'obelisk'");

        $manager->start();
    }

    #[Test]    public function it_resolves_the_obelisk_consumer_for_obelisk_mode()
    {
        config()->set('atp-signals.mode', 'obelisk');

        $manager = $this->app->make(SignalManager::class);

        $this->assertSame('obelisk', $manager->getMode());
    }

    #[Test]    public function it_reports_the_configured_mode()
    {
        config()->set('atp-signals.mode', 'firehose');

        $manager = $this->app->make(SignalManager::class);

        $this->assertSame('firehose', $manager->getMode());
    }

    #[Test]    public function it_defaults_to_jetstream_when_config_is_at_default()
    {
        // Reset the config to the package's published default
        config()->set('atp-signals.mode', config('atp-signals.mode', 'jetstream'));

        $manager = $this->app->make(SignalManager::class);

        $this->assertSame('jetstream', $manager->getMode());
    }
}
