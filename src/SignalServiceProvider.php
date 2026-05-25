<?php

namespace SocialDept\AtpSignals;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpSignals\Commands\ConsumeCommand;
use SocialDept\AtpSignals\Commands\InstallCommand;
use SocialDept\AtpSignals\Commands\ListSignalsCommand;
use SocialDept\AtpSignals\Commands\MakeSignalCommand;
use SocialDept\AtpSignals\Commands\TapAddRepoCommand;
use SocialDept\AtpSignals\Commands\TapRemoveRepoCommand;
use SocialDept\AtpSignals\Commands\TapRestartCommand;
use SocialDept\AtpSignals\Commands\TapStatusCommand;
use SocialDept\AtpSignals\Commands\TestSignalCommand;
use SocialDept\AtpSignals\Contracts\CursorStore;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\Services\FirehoseConsumer;
use SocialDept\AtpSignals\Services\JetstreamConsumer;
use SocialDept\AtpSignals\Services\SignalManager;
use SocialDept\AtpSignals\Services\SignalRegistry;
use SocialDept\AtpSignals\Storage\DatabaseCursorStore;
use SocialDept\AtpSignals\Storage\FileCursorStore;
use SocialDept\AtpSignals\Storage\RedisCursorStore;
use SocialDept\AtpSignals\Tap\TapBulkWebhookController;
use SocialDept\AtpSignals\Tap\TapClient;
use SocialDept\AtpSignals\Tap\TapWebhookController;

class SignalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atp-signals.php', 'atp-signals');

        // Register cursor store
        $this->app->singleton(CursorStore::class, function ($app) {
            return match (config('atp-signals.cursor_storage')) {
                'redis' => new RedisCursorStore(),
                'file' => new FileCursorStore(),
                default => new DatabaseCursorStore(),
            };
        });

        // Register signal registry
        $this->app->singleton(SignalRegistry::class, function ($app) {
            $registry = new SignalRegistry();

            // Register configured signals
            foreach (config('atp-signals.signals', []) as $signal) {
                $registry->register($signal);
            }

            // Auto-discover signals
            $registry->discover();

            return $registry;
        });

        // Register event dispatcher
        $this->app->singleton(EventDispatcher::class, function ($app) {
            return new EventDispatcher($app->make(SignalRegistry::class));
        });

        // Register Jetstream consumer
        $this->app->singleton(JetstreamConsumer::class, function ($app) {
            return new JetstreamConsumer(
                $app->make(CursorStore::class),
                $app->make(SignalRegistry::class),
                $app->make(EventDispatcher::class),
            );
        });

        // Register Firehose consumer
        $this->app->singleton(FirehoseConsumer::class, function ($app) {
            return new FirehoseConsumer(
                $app->make(CursorStore::class),
                $app->make(SignalRegistry::class),
                $app->make(EventDispatcher::class),
            );
        });

        // Register Signal manager
        $this->app->singleton(SignalManager::class, function ($app) {
            return new SignalManager(
                $app->make(FirehoseConsumer::class),
                $app->make(JetstreamConsumer::class),
            );
        });

        // Register Tap client
        $this->app->singleton(TapClient::class, function ($app) {
            return new TapClient(
                config('atp-signals.tap.base_url'),
                config('atp-signals.tap.admin_password'),
            );
        });

        $this->registerTapDatabase();
    }

    protected function registerTapDatabase(): void
    {
        if (! config('atp-signals.tap.enabled', false)) {
            return;
        }

        config([
            'database.connections.tap' => [
                'driver' => 'sqlite',
                'database' => config('atp-signals.tap.database_path', base_path('tap.db')),
                'prefix' => '',
                'foreign_key_constraints' => false,
                'read_only' => true,
            ],
        ]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__.'/../config/atp-signals.php' => config_path('atp-signals.php'),
            ], 'atp-signals-config');

            // Publish migrations
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'signal-migrations');

            // Register commands
            $this->commands([
                InstallCommand::class,
                ConsumeCommand::class,
                ListSignalsCommand::class,
                MakeSignalCommand::class,
                TestSignalCommand::class,
                TapAddRepoCommand::class,
                TapRemoveRepoCommand::class,
                TapRestartCommand::class,
                TapStatusCommand::class,
            ]);
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register Tap webhook route
        $this->registerTapWebhookRoute();
    }

    protected function registerTapWebhookRoute(): void
    {
        if (! config('atp-signals.tap.enabled', false)) {
            return;
        }

        Route::post(
            config('atp-signals.tap.webhook_path', '/_atp/tap/webhook'),
            TapWebhookController::class
        )
            ->middleware(config('atp-signals.tap.webhook_middleware', ['api']))
            ->name('signal.tap.webhook');

        Route::post(
            config('atp-signals.tap.webhook_path', '/_atp/tap/webhook').'/bulk',
            TapBulkWebhookController::class
        )
            ->middleware(config('atp-signals.tap.webhook_middleware', ['api']))
            ->name('signal.tap.webhook.bulk');
    }
}
