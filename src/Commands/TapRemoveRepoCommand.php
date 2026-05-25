<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Tap\TapClient;

class TapRemoveRepoCommand extends Command
{
    protected $signature = 'signal:tap:remove {did : The DID of the repo to stop tracking}';

    protected $description = 'Remove a repo from Tap tracking';

    public function handle(TapClient $client): int
    {
        $did = $this->argument('did');

        $this->info("Removing repo: {$did}");

        try {
            $client->removeRepo($did);
            $this->info("Repo removed successfully: {$did}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to remove repo: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
