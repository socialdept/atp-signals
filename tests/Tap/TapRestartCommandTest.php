<?php

namespace SocialDept\AtpSignals\Tests\Tap;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Enums\SignalEventType;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Services\SignalRegistry;
use SocialDept\AtpSignals\Signals\Signal;
use SocialDept\AtpSignals\SignalServiceProvider;

class TapRestartCommandTest extends TestCase
{
    protected string $envPath;

    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->envPath = sys_get_temp_dir().'/atp-signals-test/tap/env';

        $app['config']->set('atp-signals.tap.enabled', true);
        $app['config']->set('atp-signals.tap.env_path', $this->envPath);
        $app['config']->set('atp-signals.tap.admin_password', 'test-secret');
        $app['config']->set('atp-signals.tap.webhook_path', '/_atp/tap/webhook');
        $app['config']->set('atp-signals.tap.restart_command', null);
        $app['config']->set('atp-signals.signals', []);
        $app['config']->set('atp-signals.auto_discovery.enabled', false);
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->envPath);
        if (File::exists($dir)) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    #[Test]    public function it_writes_env_file_with_collections_from_signals()
    {
        $this->registerSignals([
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['site.standard.publication', 'site.standard.document'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
        ]);

        $this->artisan('signal:tap:restart', ['--write-only' => true])
            ->assertSuccessful();

        $this->assertFileExists($this->envPath);

        $contents = File::get($this->envPath);
        $this->assertStringContainsString('site.standard.document', $contents);
        $this->assertStringContainsString('site.standard.publication', $contents);
        $this->assertStringContainsString('TAP_COLLECTION_FILTERS=', $contents);
        $this->assertStringContainsString('TAP_WEBHOOK_URL=', $contents);
        $this->assertStringContainsString('TAP_ADMIN_PASSWORD=', $contents);
    }

    #[Test]    public function it_deduplicates_collections_across_signals()
    {
        $this->registerSignals([
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['app.bsky.actor.profile', 'site.standard.publication'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['site.standard.publication', 'site.standard.document'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
        ]);

        $this->artisan('signal:tap:restart', ['--write-only' => true])
            ->assertSuccessful();

        $contents = File::get($this->envPath);

        // "site.standard.publication" should appear only once in the filters value
        preg_match("/TAP_COLLECTION_FILTERS='([^']+)'/", $contents, $matches);
        $filters = $matches[1];
        $collections = explode(',', $filters);

        $this->assertCount(3, $collections);
    }

    #[Test]    public function it_skips_signals_with_null_collections()
    {
        $this->registerSignals([
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return null;
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['site.standard.publication'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
        ]);

        $this->artisan('signal:tap:restart', ['--write-only' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('listens to all collections');

        $contents = File::get($this->envPath);
        $this->assertStringContainsString('site.standard.publication', $contents);
    }

    #[Test]    public function it_fails_when_no_collections_found()
    {
        $this->artisan('signal:tap:restart', ['--write-only' => true])
            ->assertFailed()
            ->expectsOutputToContain('No collections found');
    }

    #[Test]    public function it_warns_when_no_restart_command_configured()
    {
        $this->registerSignals([
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['site.standard.publication'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
        ]);

        $this->artisan('signal:tap:restart')
            ->assertSuccessful()
            ->expectsOutputToContain('No restart command configured');
    }

    #[Test]    public function it_executes_restart_command_when_configured()
    {
        Process::fake();

        config()->set('atp-signals.tap.restart_command', 'supervisorctl restart tap');

        $this->registerSignals([
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['site.standard.publication'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
        ]);

        $this->artisan('signal:tap:restart')
            ->assertSuccessful()
            ->expectsOutputToContain('Tap restarted successfully');

        Process::assertRan('supervisorctl restart tap');
    }

    #[Test]    public function write_only_flag_skips_restart()
    {
        Process::fake();

        config()->set('atp-signals.tap.restart_command', 'supervisorctl restart tap');

        $this->registerSignals([
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['site.standard.publication'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
        ]);

        $this->artisan('signal:tap:restart', ['--write-only' => true])
            ->assertSuccessful();

        Process::assertNothingRan();
    }

    #[Test]    public function it_creates_directory_if_it_does_not_exist()
    {
        $dir = dirname($this->envPath);
        if (File::exists($dir)) {
            File::deleteDirectory($dir);
        }

        $this->registerSignals([
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['site.standard.publication'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
        ]);

        $this->artisan('signal:tap:restart', ['--write-only' => true])
            ->assertSuccessful();

        $this->assertDirectoryExists($dir);
        $this->assertFileExists($this->envPath);
    }

    #[Test]    public function it_preserves_wildcard_collections()
    {
        $this->registerSignals([
            new class () extends Signal {
                public function eventTypes(): array
                {
                    return [SignalEventType::Commit];
                }

                public function collections(): ?array
                {
                    return ['app.bsky.feed.*', 'site.standard.publication'];
                }

                public function handle(SignalEvent $event): void
                {
                }
            },
        ]);

        $this->artisan('signal:tap:restart', ['--write-only' => true])
            ->assertSuccessful();

        $contents = File::get($this->envPath);
        $this->assertStringContainsString('app.bsky.feed.*', $contents);
    }

    /**
     * Register signal instances directly into the registry.
     *
     * @param  array<Signal>  $signals
     */
    protected function registerSignals(array $signals): void
    {
        $registry = $this->app->make(SignalRegistry::class);

        foreach ($signals as $signal) {
            $class = $signal::class;
            $this->app->instance($class, $signal);
            $registry->register($class);
        }
    }
}
