<?php

namespace SocialDept\AtpSignals\Services;

use InvalidArgumentException;

class SignalManager
{
    public function __construct(
        protected FirehoseConsumer $firehoseConsumer,
        protected JetstreamConsumer $jetstreamConsumer,
    ) {
    }

    /**
     * Start consuming events from the AT Protocol.
     */
    public function start(?int $cursor = null): void
    {
        $this->resolveConsumer()->start($cursor);
    }

    /**
     * Stop consuming events.
     */
    public function stop(): void
    {
        $this->resolveConsumer()->stop();
    }

    /**
     * Get the current consumer mode.
     */
    public function getMode(): string
    {
        return config('atp-signals.mode', 'jetstream');
    }

    /**
     * Resolve the appropriate consumer based on configuration.
     */
    protected function resolveConsumer(): FirehoseConsumer|JetstreamConsumer
    {
        $mode = $this->getMode();

        return match ($mode) {
            'firehose' => $this->firehoseConsumer,
            'jetstream' => $this->jetstreamConsumer,
            'tap' => throw new InvalidArgumentException(
                'Tap mode uses webhook delivery — there is no persistent consumer to start. '
                .'Ensure your Tap service is configured to POST events to your webhook endpoint.'
            ),
            default => throw new InvalidArgumentException("Invalid signal mode: {$mode}. Must be 'jetstream', 'firehose', or 'tap'."),
        };
    }
}
