<?php

namespace SocialDept\AtpSignals;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpSignals\Commands\ConsumeCommand;
use SocialDept\AtpSignals\Commands\InstallCommand;
use SocialDept\AtpSignals\Commands\ListSignalsCommand;
use SocialDept\AtpSignals\Commands\MakeSignalCommand;
use SocialDept\AtpSignals\Commands\ObeliskPauseCommand;
use SocialDept\AtpSignals\Commands\ObeliskPullCommand;
use SocialDept\AtpSignals\Commands\ObeliskResumeCommand;
use SocialDept\AtpSignals\Commands\ObeliskRewindCommand;
use SocialDept\AtpSignals\Commands\ObeliskStatusCommand;
use SocialDept\AtpSignals\Commands\ObeliskSubscribeCommand;
use SocialDept\AtpSignals\Commands\TestSignalCommand;
use SocialDept\AtpSignals\Contracts\CursorStore;
use SocialDept\AtpSignals\Obelisk\ObeliskBatchProcessor;
use SocialDept\AtpSignals\Obelisk\ObeliskClient;
use SocialDept\AtpSignals\Obelisk\ObeliskConsumer;
use SocialDept\AtpSignals\Obelisk\ObeliskEventNormalizer;
use SocialDept\AtpSignals\Obelisk\ObeliskWebhookController;
use SocialDept\AtpSignals\Services\EventDispatcher;
use SocialDept\AtpSignals\Services\FirehoseConsumer;
use SocialDept\AtpSignals\Services\JetstreamConsumer;
use SocialDept\AtpSignals\Services\SignalManager;
use SocialDept\AtpSignals\Services\SignalRegistry;
use SocialDept\AtpSignals\Storage\CursorStoreFactory;

class SignalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atp-signals.php', 'atp-signals');

        // The default cursor store: the shared slot the firehose consumer and
        // anything resolving the contract directly both use. Jetstream scopes
        // its own key below rather than rebinding this one.
        $this->app->singleton(CursorStore::class, fn () => $this->makeCursorStore());

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
                // v2 seq cursors live under their own key: a v1 time_us and a
                // v2 seq must never be read as one another. Scoped to this
                // consumer so the firehose keeps its own position, the same
                // way ObeliskConsumer takes its own store below.
                $this->makeCursorStore(
                    (int) config('atp-signals.jetstream_version', 1) === 2 ? 'jetstream-v2' : null
                ),
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

        $this->registerObelisk();

        // Register Signal manager
        $this->app->singleton(SignalManager::class, function ($app) {
            return new SignalManager(
                $app->make(FirehoseConsumer::class),
                $app->make(JetstreamConsumer::class),
                $app->make(ObeliskConsumer::class),
            );
        });
    }

    /**
     * Register the Obelisk archive client and the push/pull plumbing around it.
     */
    protected function registerObelisk(): void
    {
        $this->app->singleton(ObeliskClient::class, function ($app) {
            return new ObeliskClient(
                config('atp-signals.obelisk.base_url'),
                config('atp-signals.obelisk.token'),
                config('atp-signals.obelisk.timeout'),
            );
        });

        $this->app->singleton(ObeliskEventNormalizer::class);

        $this->app->singleton(ObeliskBatchProcessor::class, function ($app) {
            return new ObeliskBatchProcessor(
                $app->make(EventDispatcher::class),
                $app->make(ObeliskEventNormalizer::class),
            );
        });

        $this->app->singleton(ObeliskConsumer::class, function ($app) {
            return new ObeliskConsumer(
                $app->make(ObeliskClient::class),
                $app->make(ObeliskBatchProcessor::class),
                // Its own cursor key: pull mode tracks Obelisk event ids, which
                // have nothing to do with Jetstream's time-based cursor.
                $this->makeCursorStore('obelisk'),
            );
        });
    }

    /**
     * Build a cursor store for the configured driver, optionally namespaced so
     * consumer modes keep independent positions.
     */
    protected function makeCursorStore(?string $key = null): CursorStore
    {
        return CursorStoreFactory::make($key);
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
                ObeliskSubscribeCommand::class,
                ObeliskStatusCommand::class,
                ObeliskRewindCommand::class,
                ObeliskPullCommand::class,
                ObeliskPauseCommand::class,
                ObeliskResumeCommand::class,
            ]);
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerObeliskWebhookRoute();
    }

    protected function registerObeliskWebhookRoute(): void
    {
        if (! config('atp-signals.obelisk.enabled', false)) {
            return;
        }

        Route::post(
            config('atp-signals.obelisk.webhook_path', '/_atp/obelisk/webhook'),
            ObeliskWebhookController::class
        )
            ->middleware(config('atp-signals.obelisk.webhook_middleware', ['api']))
            ->name('signal.obelisk.webhook');
    }
}
