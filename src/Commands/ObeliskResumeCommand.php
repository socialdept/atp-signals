<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Commands\Concerns\ResolvesObeliskSubscription;
use SocialDept\AtpSignals\Obelisk\ObeliskClient;

/**
 * Restart push delivery from wherever the cursor stopped.
 *
 * Also the cure for a subscription the archive marked `failing` after a long run
 * of refused deliveries: setting the status resets the failure count and the
 * backoff, so delivery restarts immediately rather than waiting one out.
 */
class ObeliskResumeCommand extends Command
{
    use ResolvesObeliskSubscription;

    protected $signature = 'signal:obelisk:resume
                            {--id= : Subscription id}
                            {--name= : Subscription name}
                            {--execute : Actually resume delivery}';

    protected $description = 'Resume a paused or failing Obelisk subscription (clears the backoff)';

    public function handle(ObeliskClient $client): int
    {
        $id = $this->resolveSubscriptionId($client);

        if ($id === null) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Subscription', (string) $id);
        $this->components->twoColumnDetail('Effect', 'Delivery restarts from the stored cursor; failure count reset');

        if (! $this->option('execute')) {
            $this->newLine();
            $this->components->warn('Dry run. Re-run with --execute to resume delivery.');

            return self::SUCCESS;
        }

        try {
            $client->updateWebhook(['id' => $id, 'status' => 'active']);
        } catch (\Throwable $e) {
            $this->components->error('Obelisk rejected the request: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Subscription {$id} active. Delivery resumes on the next tick.");

        return self::SUCCESS;
    }
}
