<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use SocialDept\AtpSignals\Services\SignalRegistry;

class TapRestartCommand extends Command
{
    protected $signature = 'signal:tap:restart {--write-only : Only write the env file, do not restart Tap}';

    protected $description = 'Write Tap env file with discovered collections and restart the Tap service';

    public function handle(SignalRegistry $registry): int
    {
        $collections = $this->resolveCollections($registry);

        if ($collections->isEmpty()) {
            $this->warn('No collections found from registered signals.');

            return self::FAILURE;
        }

        $this->info('Resolved collections from registered signals:');
        $collections->each(fn ($c) => $this->line("  - {$c}"));

        $envPath = $this->writeEnvFile($collections->all());

        $this->info("Tap env file written to: {$envPath}");

        if ($this->option('write-only')) {
            return self::SUCCESS;
        }

        $restartCommand = config('atp-signals.tap.restart_command');

        if (! $restartCommand) {
            $this->warn('No restart command configured (atp-signals.tap.restart_command). Restart Tap manually.');

            return self::SUCCESS;
        }

        $this->info("Restarting Tap: {$restartCommand}");

        $result = Process::run($restartCommand);

        if ($result->failed()) {
            $this->error("Restart failed: {$result->errorOutput()}");

            return self::FAILURE;
        }

        $this->info('Tap restarted successfully.');

        return self::SUCCESS;
    }

    /**
     * Resolve all unique collections from registered signals.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function resolveCollections(SignalRegistry $registry): \Illuminate\Support\Collection
    {
        return $registry->all()
            ->map(function ($signal) {
                $collections = $signal->collections();

                if ($collections === null) {
                    $class = get_class($signal);
                    $this->warn("Signal {$class} listens to all collections (null) — skipping.");

                    return [];
                }

                return $collections;
            })
            ->flatten()
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Write the Tap env file.
     *
     * @param  array<string>  $collections
     */
    protected function writeEnvFile(array $collections): string
    {
        $envPath = config('atp-signals.tap.env_path', storage_path('tap/env'));

        File::ensureDirectoryExists(dirname($envPath));

        $adminPassword = config('atp-signals.tap.admin_password', '');
        $useBatcher = (bool) config('atp-signals.tap.batcher.enabled', false);

        $directWebhookUrl = url(config('atp-signals.tap.webhook_path', '/_atp/tap/webhook'));
        $bulkWebhookUrl = url(config('atp-signals.tap.webhook_path', '/_atp/tap/webhook').'/bulk');

        $batcherHost = config('atp-signals.tap.batcher.host', '127.0.0.1');
        $batcherPort = (int) config('atp-signals.tap.batcher.port', 9999);
        $batcherPath = config('atp-signals.tap.batcher.path', '/');
        $batchSize = (int) config('atp-signals.tap.batcher.batch_size', 500);
        $batchTimeout = (int) config('atp-signals.tap.batcher.batch_timeout', 5000);

        // When the batcher is enabled, Tap POSTs to the batcher (which buffers
        // and bulk-forwards to Laravel). Otherwise Tap POSTs directly to the
        // Laravel webhook one event at a time.
        $tapWebhookUrl = $useBatcher
            ? "http://{$batcherHost}:{$batcherPort}{$batcherPath}"
            : $directWebhookUrl;

        $lines = [
            'TAP_WEBHOOK_URL='.escapeshellarg($tapWebhookUrl),
            'TAP_ADMIN_PASSWORD='.escapeshellarg($adminPassword),
            'TAP_COLLECTION_FILTERS='.escapeshellarg(implode(',', $collections)),
        ];

        if ($useBatcher) {
            $insecureTls = (bool) config('atp-signals.tap.batcher.insecure_tls', false);

            $lines = array_merge($lines, [
                '',
                '# Batcher proxy settings',
                'BATCHER_HOST='.escapeshellarg($batcherHost),
                'BATCHER_PORT='.$batcherPort,
                'BATCHER_PATH='.escapeshellarg($batcherPath),
                'WEBHOOK_BULK_URL='.escapeshellarg($bulkWebhookUrl),
                'WEBHOOK_AUTH_PASSWORD='.escapeshellarg($adminPassword),
                'BATCH_SIZE='.$batchSize,
                'BATCH_TIMEOUT_MS='.$batchTimeout,
                'BATCHER_INSECURE_TLS='.($insecureTls ? 'true' : 'false'),
            ]);
        }

        File::put($envPath, implode("\n", $lines)."\n");

        return $envPath;
    }
}
