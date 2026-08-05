<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Commands\Concerns\ResolvesObeliskSubscription;
use SocialDept\AtpSignals\Obelisk\ObeliskClient;

class ObeliskStatusCommand extends Command
{
    use ResolvesObeliskSubscription;

    protected $signature = 'signal:obelisk:status
                            {--name= : Only show the subscription with this name}';

    protected $description = 'Show Obelisk archive reachability and webhook subscription state';

    public function handle(ObeliskClient $client): int
    {
        $baseUrl = config('atp-signals.obelisk.base_url');

        $this->components->twoColumnDetail('Archive', (string) $baseUrl);
        $this->components->twoColumnDetail('Mode', (string) config('atp-signals.mode'));
        $this->components->twoColumnDetail(
            'Push enabled',
            config('atp-signals.obelisk.enabled') ? '<fg=green>yes</>' : '<fg=gray>no</>'
        );

        // The pull cursor is the handoff number: `subscribe --from-cursor=pull`
        // starts push delivery here, so a drained backfill is not replayed.
        $pull = $this->pullCursor();
        $this->components->twoColumnDetail(
            'Pull cursor',
            $pull === null ? '<fg=gray>never run</>' : (string) $pull
        );

        $max = (int) config('atp-signals.obelisk.max_queue_depth', 0);
        $this->components->twoColumnDetail(
            'Queue brake',
            $max > 0 ? "503 at {$max} queued jobs" : '<fg=yellow>disabled</>'
        );

        try {
            $healthy = $client->healthy();
        } catch (\Throwable $e) {
            $this->components->error("Cannot reach {$baseUrl}: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail(
            'Reachable',
            $healthy ? '<fg=green>yes</>' : '<fg=red>no</>'
        );

        try {
            $webhooks = $client->getWebhooks()['webhooks'] ?? [];
        } catch (\Throwable $e) {
            $this->newLine();
            $this->components->error('Could not list subscriptions: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($name = $this->option('name')) {
            $webhooks = array_filter($webhooks, fn ($webhook) => ($webhook['name'] ?? null) === $name);
        }

        $this->newLine();

        if ($webhooks === []) {
            $this->components->warn('No webhook subscriptions. Create one with signal:obelisk:subscribe.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Cursor', 'Status', 'Failures', 'Last delivery', 'Collections'],
            array_map(fn ($webhook) => [
                $webhook['id'] ?? '',
                $webhook['name'] ?? '',
                $webhook['cursor'] ?? '',
                $webhook['status'] ?? '',
                $webhook['failureCount'] ?? 0,
                $webhook['lastDeliveryAt'] ?? 'never',
                implode(', ', $webhook['collections'] ?? []) ?: 'all',
            ], $webhooks),
        );

        return self::SUCCESS;
    }
}
