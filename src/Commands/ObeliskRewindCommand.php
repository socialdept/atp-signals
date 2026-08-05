<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Commands\Concerns\ResolvesObeliskSubscription;
use SocialDept\AtpSignals\Obelisk\ObeliskClient;
use SocialDept\AtpSignals\Storage\CursorStoreFactory;

class ObeliskRewindCommand extends Command
{
    use ResolvesObeliskSubscription;

    protected $signature = 'signal:obelisk:rewind
                            {cursor : Event id to resume from — 0 replays the whole log}
                            {--id= : Subscription id (push mode)}
                            {--name= : Subscription name (push mode)}
                            {--pull : Rewind the local pull cursor instead of a subscription}
                            {--execute : Actually move the cursor}';

    protected $description = 'Rewind an Obelisk cursor to replay events';

    public function handle(ObeliskClient $client): int
    {
        $cursor = (int) $this->argument('cursor');

        if ($this->option('pull')) {
            return $this->rewindPull($cursor);
        }

        $id = $this->resolveSubscriptionId($client);

        if ($id === null) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Subscription', (string) $id);
        $this->components->twoColumnDetail('New cursor', (string) $cursor);
        $this->components->twoColumnDetail('Effect', 'Obelisk redelivers every event after this id');

        if (! $this->option('execute')) {
            $this->newLine();
            $this->components->warn('Dry run. Re-run with --execute to move the cursor.');

            return self::SUCCESS;
        }

        try {
            $client->rewindWebhook($id, $cursor);
        } catch (\Throwable $e) {
            $this->components->error('Obelisk rejected the request: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Subscription {$id} rewound to cursor {$cursor}. Redelivery starts on the next tick.");

        return self::SUCCESS;
    }

    protected function rewindPull(int $cursor): int
    {
        $this->components->twoColumnDetail('Pull cursor', (string) $cursor);
        $this->components->twoColumnDetail('Effect', 'The next signal:consume run re-reads from this id');

        if (! $this->option('execute')) {
            $this->newLine();
            $this->components->warn('Dry run. Re-run with --execute to move the cursor.');

            return self::SUCCESS;
        }

        CursorStoreFactory::make('obelisk')->set($cursor);

        $this->newLine();
        $this->components->info("Pull cursor set to {$cursor}.");

        return self::SUCCESS;
    }

}
