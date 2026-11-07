# Queue Integration

Processing AT Protocol events can be resource-intensive. Signal's queue integration lets you handle events asynchronously, preventing bottlenecks and improving performance.

## Why Use Queues?

### Without Queues (Synchronous)

```php
public function handle(SignalEvent $event): void
{
    $this->performExpensiveAnalysis($event);  // Blocks for 2 seconds
    $this->sendNotifications($event);          // Blocks for 1 second
    $this->updateDatabase($event);             // Blocks for 0.5 seconds
}
```

**Problems:**
- Consumer blocks while processing (3.5 seconds per event)
- Events queue up during slow operations
- Risk of disconnection during long processing
- Can't scale horizontally
- Memory issues with long-running processes

### With Queues (Asynchronous)

```php
public function shouldQueue(): bool
{
    return true;
}

public function handle(SignalEvent $event): void
{
    $this->performExpensiveAnalysis($event);  // Runs in background
    $this->sendNotifications($event);          // Runs in background
    $this->updateDatabase($event);             // Runs in background
}
```

**Benefits:**
- Consumer stays responsive
- Processing happens in parallel
- Scale by adding queue workers
- Better memory management
- Automatic retry on failures

## Basic Queue Configuration

### Enable Queueing

Simply return `true` from `shouldQueue()`:

```php
class MySignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function shouldQueue(): bool
    {
        return true; // Enable queuing
    }

    public function handle(SignalEvent $event): void
    {
        // This now runs in a queue job
    }
}
```

That's it! Signal automatically:
- Creates a queue job for each event
- Serializes the event data
- Dispatches to Laravel's queue system
- Handles retries and failures

### Default Queue Configuration

Signal uses your Laravel queue configuration:

```env
# Default queue connection
QUEUE_CONNECTION=redis

# Signal-specific queue (optional)
SIGNAL_QUEUE=signal

# Signal queue connection (optional)
SIGNAL_QUEUE_CONNECTION=redis
```

## Customizing Queue Behavior

### Specify Queue Name

Send events to a specific queue:

```php
public function shouldQueue(): bool
{
    return true;
}

public function queue(): string
{
    return 'high-priority'; // Queue name
}
```

Now your events go to the `high-priority` queue:

```bash
php artisan queue:work --queue=high-priority
```

### Specify Queue Connection

Use a different queue connection:

```php
public function shouldQueue(): bool
{
    return true;
}

public function queueConnection(): string
{
    return 'redis'; // Connection name
}
```

### Combine Queue Configuration

```php
public function shouldQueue(): bool
{
    return true;
}

public function queueConnection(): string
{
    return 'redis';
}

public function queue(): string
{
    return 'signal-events';
}
```

## Running Queue Workers

### Start a Worker

Process queued events:

```bash
php artisan queue:work
```

### Process Specific Queue

```bash
php artisan queue:work --queue=signal
```

### Multiple Queues with Priority

Process high-priority queue first:

```bash
php artisan queue:work --queue=high-priority,default
```

### Scale with Multiple Workers

Run multiple workers for throughput:

```bash
# Terminal 1
php artisan queue:work --queue=signal

# Terminal 2
php artisan queue:work --queue=signal

# Terminal 3
php artisan queue:work --queue=signal
```

### Supervisor Configuration

For production, use Supervisor to manage workers:

```ini
[program:signal-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --sleep=3 --tries=3 --queue=signal
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

This creates 4 workers processing the `signal` queue.

## Error Handling

### Failed Method

Handle job failures:

```php
public function shouldQueue(): bool
{
    return true;
}

public function handle(SignalEvent $event): void
{
    // Your logic that might fail
    $this->riskyOperation($event);
}

public function failed(SignalEvent $event, \Throwable $exception): void
{
    Log::error('Signal processing failed', [
        'signal' => static::class,
        'did' => $event->did,
        'collection' => $event->getCollection(),
        'error' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);

    // Optional: Send alerts
    $this->notifyAdmin($exception);

    // Optional: Store for manual review
    FailedSignal::create([
        'event_data' => $event->toArray(),
        'exception' => $exception->getMessage(),
    ]);
}
```

### Automatic Retries

Laravel automatically retries failed jobs:

```bash
# Retry up to 3 times
php artisan queue:work --tries=3
```

Configure retry delay:

```php
public function retryAfter(): int
{
    return 60; // Wait 60 seconds before retry
}
```

### Exponential Backoff

Increase delay between retries:

```php
public function backoff(): array
{
    return [10, 30, 60]; // 10s, then 30s, then 60s
}
```

## Performance Optimization

### Batch Processing

Process multiple events at once:

```php
use Illuminate\Support\Collection;

class BatchPostSignal extends Signal
{
    public function shouldQueue(): bool
{
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        // Collect events in cache
        $events = Cache::get('pending_posts', []);
        $events[] = $event->toArray();

        Cache::put('pending_posts', $events, now()->addMinutes(5));

        // Process in batches of 100
        if (count($events) >= 100) {
            $this->processBatch($events);
            Cache::forget('pending_posts');
        }
    }

    private function processBatch(array $events): void
    {
        // Bulk insert, API calls, etc.
    }
}
```

### Conditional Queuing

Queue only expensive operations:

```php
public function shouldQueue(): bool
{
    // Queue during high traffic
    return now()->hour >= 9 && now()->hour <= 17;
}
```

Or based on event type:

```php
public function handle(SignalEvent $event): void
{
    if ($this->isExpensive($event)) {
        dispatch(function () use ($event) {
            $this->handleExpensive($event);
        })->onQueue('slow-operations');
    } else {
        $this->handleQuick($event);
    }
}
```

### Rate Limiting

Prevent overwhelming external APIs:

```php
use Illuminate\Support\Facades\RateLimiter;

public function handle(SignalEvent $event): void
{
    RateLimiter::attempt(
        'api-calls',
        $perMinute = 100,
        function () use ($event) {
            $this->callExternalAPI($event);
        }
    );
}
```

## Common Patterns

### High-Volume Signal

Process millions of events efficiently:

```php
class HighVolumeSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function queue(): string
    {
        return 'high-volume';
    }

    public function handle(SignalEvent $event): void
    {
        // Lightweight processing only
        $this->incrementCounter($event);
    }
}
```

Run many workers:

```bash
# 10 workers on high-volume queue
php artisan queue:work --queue=high-volume --workers=10
```

### Priority Queues

Different priorities for different events:

```php
class PrioritySignal extends Signal
{
    public function shouldQueue(): bool
    {
        return true;
    }

    public function queue(): string
    {
        // Determine priority based on event
        return $this->getQueueForEvent();
    }

    private function getQueueForEvent(): string
    {
        // Check event attributes
        // Return 'high', 'medium', or 'low'
    }
}
```

Process high-priority first:

```bash
php artisan queue:work --queue=high,medium,low
```

### Delayed Processing

Delay event processing:

```php
public function handle(SignalEvent $event): void
{
    // Dispatch with delay
    dispatch(function () use ($event) {
        $this->processLater($event);
    })->delay(now()->addMinutes(5));
}
```

### Scheduled Batch Processing

Collect events and process on schedule:

```php
// Signal collects events
class CollectorSignal extends Signal
{
    public function handle(SignalEvent $event): void
    {
        PendingEvent::create([
            'data' => $event->toArray(),
        ]);
    }
}

// Scheduled command processes batch
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        $events = PendingEvent::all();
        $this->processBatch($events);
        PendingEvent::truncate();
    })->hourly();
}
```

## Monitoring Queues

### Check Queue Status

```bash
# View failed jobs
php artisan queue:failed

# Retry failed job
php artisan queue:retry {id}

# Retry all failed
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### Queue Metrics

Track queue performance:

```php
use Illuminate\Support\Facades\Queue;

Queue::after(function ($connection, $job, $data) {
    Log::info('Job processed', [
        'queue' => $job->queue,
        'class' => $job->resolveName(),
        'attempts' => $job->attempts(),
    ]);
});
```

### Horizon (Recommended)

Use Laravel Horizon for Redis queues:

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan horizon
```

View dashboard at `/horizon`.

## Testing Queued Signals

### Test with Fake Queue

```php
use Illuminate\Support\Facades\Queue;

/** @test */
public function it_queues_events()
{
    Queue::fake();

    $signal = new MySignal();
    $event = $this->createSampleEvent();

    // Assert queue behavior
    $this->assertTrue($signal->shouldQueue());

    // Process would normally queue
    $signal->handle($event);

    // Verify job was queued
    Queue::assertPushed(SignalJob::class);
}
```

### Test Synchronously

Disable queueing for tests:

```php
/** @test */
public function it_processes_events()
{
    config(['queue.default' => 'sync']);

    $signal = new MySignal();
    $event = $this->createSampleEvent();

    $signal->handle($event);

    // Assert processing happened
    $this->assertDatabaseHas('posts', [...]);
}
```

[Learn more about testing →](testing.md)

## Production Checklist

### Infrastructure

- [ ] Queue driver configured (Redis recommended)
- [ ] Supervisor installed and configured
- [ ] Multiple workers running
- [ ] Worker auto-restart enabled
- [ ] Logs configured and monitored

### Configuration

- [ ] Queue connection set correctly
- [ ] Queue names configured
- [ ] Retry attempts configured
- [ ] Timeout values appropriate
- [ ] Memory limits set

### Monitoring

- [ ] Queue length monitored
- [ ] Failed jobs tracked
- [ ] Worker health checked
- [ ] Processing times measured
- [ ] Horizon installed (if using Redis)

### Scaling

- [ ] Worker count appropriate for volume
- [ ] Priority queues configured
- [ ] Rate limiting implemented
- [ ] Database connection pooling enabled
- [ ] Redis maxmemory policy set

## Common Issues

### Queue Jobs Not Processing

**Check worker is running:**
```bash
php artisan queue:work
```

**Check queue connection:**
```php
// Should match QUEUE_CONNECTION
config('queue.default')
```

### Jobs Timing Out

**Increase timeout:**
```bash
php artisan queue:work --timeout=300
```

**Or in Signal:**
```php
public function timeout(): int
{
    return 300; // 5 minutes
}
```

### Memory Leaks

**Restart workers periodically:**
```bash
php artisan queue:work --max-jobs=1000
```

Or:
```bash
php artisan queue:work --max-time=3600
```

### Failed Jobs Piling Up

**Review failures:**
```bash
php artisan queue:failed
```

**Retry or delete:**
```bash
php artisan queue:retry all
# or
php artisan queue:flush
```

## Next Steps

- **[Review configuration options →](configuration.md)** - Fine-tune queue settings
- **[Learn about testing →](testing.md)** - Test queued Signals
- **[See real-world examples →](examples.md)** - Learn from production queue patterns
