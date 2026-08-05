<?php

namespace SocialDept\AtpSignals\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SocialDept\AtpSignals\Obelisk\ObeliskBatchProcessor;

/**
 * One job per Obelisk delivery, not per event — the batch is the unit of work
 * so events keep their cursor order while the webhook request returns fast.
 */
class ProcessObeliskBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function __construct(
        protected array $events,
        protected ?string $subscription = null,
    ) {
    }

    public function handle(ObeliskBatchProcessor $processor): void
    {
        $processor->process($this->events);
    }
}
