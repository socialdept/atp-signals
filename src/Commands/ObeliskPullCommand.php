<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Obelisk\ObeliskConsumer;
use SocialDept\AtpSignals\Services\SignalRegistry;

class ObeliskPullCommand extends Command
{
    protected $signature = 'signal:obelisk:pull
                            {--cursor= : Start from a specific event id instead of the stored cursor}
                            {--once : Process a single page and exit}';

    protected $description = 'Drain new events from an Obelisk archive and exit (for scheduled catch-up)';

    public function handle(ObeliskConsumer $consumer, SignalRegistry $registry): int
    {
        if ($registry->all()->isEmpty()) {
            $this->components->warn('No signals registered. Create signals in `app/Signals` or register them in `config/atp-signals.php`.');

            return self::FAILURE;
        }

        $cursor = $this->option('cursor') !== null ? (int) $this->option('cursor') : null;

        try {
            $processed = $this->option('once')
                ? $consumer->pullOnce($cursor)
                : $consumer->drain($cursor);
        } catch (\Throwable $e) {
            $this->components->error('Pull failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($processed === null) {
            $this->components->error('Could not reach the archive. The cursor did not move.');

            return self::FAILURE;
        }

        $this->components->info("Processed {$processed} ".str('event')->plural($processed).'.');

        return self::SUCCESS;
    }
}
