<?php

namespace SocialDept\AtpSignals\Tests\Obelisk;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\SignalServiceProvider;

class ObeliskRouteRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-signals.obelisk.enabled', false);
    }

    #[Test]    public function it_does_not_register_the_webhook_route_when_obelisk_is_disabled()
    {
        $names = collect($this->app['router']->getRoutes())->map(fn ($route) => $route->getName());

        $this->assertNotContains('signal.obelisk.webhook', $names);
        $this->postJson('/_atp/obelisk/webhook', ['events' => []])->assertNotFound();
    }
}
