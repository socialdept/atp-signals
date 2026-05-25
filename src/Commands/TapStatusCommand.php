<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Tap\TapClient;

class TapStatusCommand extends Command
{
    protected $signature = 'signal:tap:status';

    protected $description = 'Check the health status of the Tap service';

    public function handle(TapClient $client): int
    {
        $this->info('Checking Tap service health...');

        try {
            $result = $client->health();
            $this->info('Tap service is healthy.');
            $this->table(['Key', 'Value'], collect($result)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->toArray());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Tap service is unreachable: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
