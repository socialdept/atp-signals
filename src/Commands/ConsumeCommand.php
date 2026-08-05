<?php

namespace SocialDept\AtpSignals\Commands;

use BackedEnum;
use Exception;
use Illuminate\Console\Command;
use SocialDept\AtpSignals\Obelisk\ObeliskConsumer;
use SocialDept\AtpSignals\Services\FirehoseConsumer;
use SocialDept\AtpSignals\Services\JetstreamConsumer;
use SocialDept\AtpSignals\Services\SignalRegistry;

class ConsumeCommand extends Command
{
    protected $signature = 'signal:consume
                            {--cursor= : Start from a specific cursor position}
                            {--fresh : Start from the beginning, ignoring stored cursor}
                            {--mode= : Override mode (jetstream, firehose, or obelisk)}';

    protected $description = 'Start consuming events from the AT Protocol';

    public function handle(SignalRegistry $registry): int
    {
        $mode = $this->determineMode();

        if ($mode === null) {
            return self::FAILURE;
        }

        $this->info("Signal: Initializing {$mode} consumer...");

        if (! $this->discoverAndValidateSignals($registry)) {
            return self::FAILURE;
        }

        $this->displayRegisteredSignals($registry);
        $this->displayModeInformation($mode);

        $cursor = $this->determineCursor();

        return $this->startConsumer($mode, $cursor);
    }

    private function determineMode(): ?string
    {
        $mode = $this->option('mode') ?? config('atp-signals.mode', 'jetstream');

        if (! in_array($mode, ['jetstream', 'firehose', 'obelisk'])) {
            $this->error("Invalid mode: {$mode}. Must be 'jetstream', 'firehose', or 'obelisk'.");

            return null;
        }

        return $mode;
    }

    private function discoverAndValidateSignals(SignalRegistry $registry): bool
    {
        $registry->discover();

        $signalCount = $registry->all()->count();

        if ($signalCount === 0) {
            $this->warn('No signals registered. Create signals in `app/Signals` or register them in `config/atp-signals.php`.');

            return false;
        }

        return true;
    }

    private function displayRegisteredSignals(SignalRegistry $registry): void
    {
        $signalCount = $registry->all()->count();

        $this->newLine();
        $this->components->info("Found {$signalCount} ".str('signal')->plural($signalCount));
        $this->newLine();

        $normalizeValue = fn ($value) => $value instanceof BackedEnum ? $value->value : $value;

        foreach ($registry->all() as $signal) {
            $className = class_basename($signal);
            $eventTypes = collect($signal->eventTypes())
                ->map(fn ($type) => "<fg=cyan>{$normalizeValue($type)}</>")
                ->join(', ');
            $collections = $signal->collections()
                ? collect($signal->collections())->map(fn ($col) => "<fg=yellow>{$col}</>")->join(', ')
                : '<fg=gray>All collections</>';

            $operations = $signal->operations()
                ? collect($signal->operations())
                    ->map(fn ($op) => "<fg=magenta>{$normalizeValue($op)}</>")
                    ->join(', ')
                : '<fg=gray>All operations</>';

            $this->line("  <fg=green>•</> <options=bold>{$className}</> → {$eventTypes} | {$collections} | {$operations}");
        }

        $this->newLine();
    }

    private function displayModeInformation(string $mode): void
    {
        match ($mode) {
            'jetstream' => $this->components->warn('Jetstream Mode: Server-side filtering (custom collections may not receive create/update)'),
            'obelisk' => $this->components->info('Obelisk Mode: Polling the archive event log from a stored cursor ('.config('atp-signals.obelisk.base_url').')'),
            default => $this->components->info('Firehose Mode: Client-side filtering (all events received, including custom collections)'),
        };

        $this->newLine();
    }

    private function determineCursor(): ?int
    {
        if ($this->option('fresh')) {
            $this->info('Starting fresh from the beginning');

            return 0; // Explicitly 0 means "start fresh, no cursor"
        }

        if ($this->option('cursor')) {
            $cursor = (int) $this->option('cursor');
            $this->info("Starting from cursor: {$cursor}");

            return $cursor;
        }

        $this->info('Resuming from stored cursor position');

        return null; // null means "use stored cursor"
    }

    private function startConsumer(string $mode, ?int $cursor): int
    {
        $consumer = match ($mode) {
            'firehose' => app(FirehoseConsumer::class),
            'obelisk' => app(ObeliskConsumer::class),
            default => app(JetstreamConsumer::class),
        };

        $this->info("Starting {$mode} consumer... Press Ctrl+C to stop.");

        try {
            $consumer->start($cursor);
        } catch (Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
