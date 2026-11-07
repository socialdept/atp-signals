# Queue Integration

Part of the [atp-signals docs](../introduction.md#documentation). Handling events on a queue instead of in the consumer process.

## Why Use Queues?

By default a Signal runs inside the consumer process. The consumer calls `handle()` for each matching event, one after another, so a slow Signal holds up every event behind it.

```php
public function handle(SignalEvent $event): void
{
    $this->performExpensiveAnalysis($event);  // The consumer waits for this
    $this->sendNotifications($event);
}
```

When a Signal is queued, the consumer only pushes a job and moves on. The work runs in your queue workers, which you can scale separately from the consumer.

## Enable Queueing

Return `true` from `shouldQueue()`:

```php
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class MySignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        // This now runs in a queue job
    }
}
```

For each matching event, the dispatcher pushes one `SocialDept\AtpSignals\Jobs\ProcessSignalJob`. The job carries the Signal instance and the `SignalEvent`, both serialized with the job, and calls your `handle()` when a worker picks it up.

Matching still happens in the consumer process. The event type, collection, operation and DID filters, and your `shouldHandle()` method, all run before the job is pushed, so keep `shouldHandle()` cheap.

> [!NOTE]
> Each event becomes its own job. With more than one worker, jobs can finish in a different order than the events arrived. If your Signal depends on order (for example a create followed by a delete of the same record), use a single worker for its queue.

## Queue Connection and Name

By default, queued Signals use the connection and queue from the package config:

```env
# Queue connection (empty means your app's default QUEUE_CONNECTION)
SIGNAL_QUEUE_CONNECTION=redis

# Queue name (defaults to "signal")
SIGNAL_QUEUE=signal
```

These map to `atp-signals.queue.connection` and `atp-signals.queue.queue`. See [configuration](../getting-started/configuration.md) for the full config file.

Because the default queue name is `signal`, a plain `php artisan queue:work` (which listens on `default`) will not pick these jobs up. Start the worker on the right queue:

```bash
php artisan queue:work --queue=signal
```

### Per-Signal Overrides

Override `queueConnection()` or `queue()` on a Signal to send its jobs somewhere else:

```php
public function shouldQueue(): bool
{
    return true;
}

public function queueConnection(): ?string
{
    return 'redis';
}

public function queue(): ?string
{
    return 'high-priority';
}
```

Both methods take no arguments, so the queue is chosen per Signal, not per event. To route events by priority, split them into separate Signals with different `queue()` values.

Workers process queues in the order listed:

```bash
php artisan queue:work --queue=high-priority,signal
```

## Running Workers in Production

Keep workers running with Supervisor (or Horizon if you use Redis). A minimal Supervisor program for the default `signal` queue:

```ini
[program:signal-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --queue=signal --tries=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/path/to/logs/signal-worker.log
stopwaitsecs=3600
```

Run the consumer (`php artisan signal:consume`) as its own long-running process next to the workers.

## Error Handling

### The `failed()` Method

Override `failed()` to react when your Signal throws:

```php
use Illuminate\Support\Facades\Log;

public function failed(SignalEvent $event, \Throwable $exception): void
{
    Log::error('Signal processing failed', [
        'signal' => static::class,
        'did' => $event->did,
        'collection' => $event->getCollection(),
        'error' => $exception->getMessage(),
    ]);
}
```

When it is called depends on how the Signal runs:

- **Synchronous**: the dispatcher catches any exception from `handle()`, logs `[Signal] Error dispatching to signal`, and calls `failed()` right away. The consumer keeps running.
- **Queued**: `ProcessSignalJob::failed()` calls your Signal's `failed()` once Laravel marks the job as failed, which is after its last attempt. If pushing the job itself throws (for example the queue connection is down), the dispatcher logs the error and calls `failed()` straight away.

### Retries and Timeouts

`ProcessSignalJob` sets no `$tries`, `$backoff` or `$timeout` of its own, and it does not read retry or timeout methods from your Signal. Control them with worker options:

```bash
php artisan queue:work --queue=signal --tries=3 --backoff=10 --timeout=300
```

## Obelisk Mode

In [Obelisk mode](../sources/obelisk.md), webhook deliveries are queued as whole batches (`ProcessObeliskBatchJob`, on the `obelisk` queue by default, see `atp-signals.obelisk.queue_*`). The batch job runs the events through the same dispatcher, so a Signal with `shouldQueue()` returning `true` gets a second job per event on its own queue. Run workers for both queues:

```bash
php artisan queue:work --queue=obelisk,signal
```

## Testing Queued Signals

Fake the queue and send an event through the dispatcher:

```php
use Illuminate\Support\Facades\Queue;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Jobs\ProcessSignalJob;
use SocialDept\AtpSignals\Services\EventDispatcher;

public function test_it_queues_post_events(): void
{
    Queue::fake();

    app(EventDispatcher::class)->dispatch(new SignalEvent(
        did: 'did:plc:test123',
        timeUs: time() * 1000000,
        kind: 'commit',
        commit: new CommitEvent(
            rev: 'test',
            operation: 'create',
            collection: 'app.bsky.feed.post',
            rkey: 'test123',
            record: (object) ['text' => 'Hello'],
        ),
    ));

    Queue::assertPushedOn('signal', ProcessSignalJob::class);
}
```

The dispatcher runs every registered Signal that matches the event, including auto-discovered ones. See [testing](testing.md) for more.

## Common Issues

### Queued Jobs Not Processing

- Check that a worker is running on the Signal's queue. The default is `signal`, not `default`.
- If `SIGNAL_QUEUE_CONNECTION` is empty, jobs go to your app's default connection (`config('queue.default')`). With the `sync` connection they run inline in the consumer.

### Memory Growth in Workers

Restart workers periodically:

```bash
php artisan queue:work --queue=signal --max-jobs=1000
# or
php artisan queue:work --queue=signal --max-time=3600
```

## Next Steps

- **[Review configuration options](../getting-started/configuration.md)**: queue settings and the rest of the config file
- **[Learn about testing](testing.md)**: test Signals and queued Signals
- **[See examples](../examples.md)**: complete Signals
