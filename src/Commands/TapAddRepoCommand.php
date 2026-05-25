<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Tap\TapClient;

class TapAddRepoCommand extends Command
{
    protected $signature = 'signal:tap:add {did : The DID of the repo to track}';

    protected $description = 'Add a repo to be tracked by Tap';

    public function handle(TapClient $client): int
    {
        $did = $this->argument('did');

        $this->info("Adding repo: {$did}");

        try {
            $client->addRepo($did);
            $this->info("Repo added successfully: {$did}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to add repo: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
