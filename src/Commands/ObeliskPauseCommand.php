<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Commands\Concerns\ResolvesObeliskSubscription;
use SocialDept\AtpSignals\Obelisk\ObeliskClient;

/**
 * Stop push delivery without losing anything.
 *
 * The blunt lever for when the automatic brake is not enough — a bad deploy, a
 * migration, an unexpected flood. The archive keeps the subscription's cursor
 * where it is, so resuming picks up exactly where it stopped.
 */
class ObeliskPauseCommand extends Command
{
    use ResolvesObeliskSubscription;

    protected $signature = 'signal:obelisk:pause
                            {--id= : Subscription id}
                            {--name= : Subscription name}
                            {--execute : Actually pause delivery}';

    protected $description = 'Pause an Obelisk webhook subscription (the cursor holds; nothing is lost)';

    public function handle(ObeliskClient $client): int
    {
        $id = $this->resolveSubscriptionId($client);

        if ($id === null) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Subscription', (string) $id);
        $this->components->twoColumnDetail('Effect', 'Delivery stops; the cursor stays put');

        if (! $this->option('execute')) {
            $this->newLine();
            $this->components->warn('Dry run. Re-run with --execute to pause delivery.');

            return self::SUCCESS;
        }

        try {
            $client->updateWebhook(['id' => $id, 'status' => 'paused']);
        } catch (\Throwable $e) {
            $this->components->error('Obelisk rejected the request: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Subscription {$id} paused. Resume with signal:obelisk:resume --id={$id} --execute.");

        return self::SUCCESS;
    }
}
