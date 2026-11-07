# Quickstart Guide

This guide will walk you through building your first Signal and consuming AT Protocol events in under 5 minutes.

## Prerequisites

Before starting, ensure you have:

- [Installed Signal](installation.md) in your Laravel application
- Run `php artisan signal:install` successfully
- Basic familiarity with Laravel

## Your First Signal

We'll build a Signal that logs every new post created on Bluesky.

### Step 1: Generate a Signal

Use the Artisan command to create a new Signal:

```bash
php artisan make:signal NewPostSignal
```

This creates `app/Signals/NewPostSignal.php` with a basic template.

### Step 2: Define the Signal

Open the generated file and update it:

```php
<?php

namespace App\Signals;

use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;
use Illuminate\Support\Facades\Log;

class NewPostSignal extends Signal
{
    /**
     * Define which event types to listen for.
     */
    public function eventTypes(): array
    {
        return ['commit']; // Listen for repository commits
    }

    /**
     * Filter by specific collections.
     */
    public function collections(): ?array
    {
        return ['app.bsky.feed.post']; // Only handle posts
    }

    /**
     * Handle the event when it arrives.
     */
    public function handle(SignalEvent $event): void
    {
        $record = $event->getRecord();

        Log::info('New post created', [
            'author' => $event->did,
            'text' => $record->text ?? null,
            'created_at' => $record->createdAt ?? null,
        ]);
    }
}
```

### Step 3: Start Consuming Events

Run the consumer to start listening:

```bash
php artisan signal:consume
```

You should see output like:

```
Starting Signal consumer in jetstream mode...
Connecting to wss://jetstream2.us-east.bsky.network...
Connected! Listening for events...
```

**Congratulations!** Your Signal is now processing every new post on Bluesky in real-time. Check your Laravel logs to see the posts coming in.

## Understanding What Just Happened

Let's break down the Signal you created:

### Event Types

```php
public function eventTypes(): array
{
    return ['commit'];
}
```

This tells Signal you want **commit** events, which represent changes to repositories (like creating posts, likes, follows, etc.).

Available event types:
- `commit` - Repository commits (most common)
- `identity` - Identity changes (handle updates)
- `account` - Account status changes

### Collections

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}
```

This filters to only **post** collections. Without this filter, your Signal would receive all commit events for every collection type.

Common collections:
- `app.bsky.feed.post` - Posts
- `app.bsky.feed.like` - Likes
- `app.bsky.graph.follow` - Follows
- `app.bsky.feed.repost` - Reposts

[Learn more about filtering →](filtering.md)

### Handler Method

```php
public function handle(SignalEvent $event): void
{
    $record = $event->getRecord();
    // Your logic here
}
```

This is where your code runs for each matching event. The `$event` object contains:

- `did` - The user's DID (decentralized identifier)
- `timeUs` - Timestamp in microseconds
- `commit` - Commit details (collection, operation, record key)
- `getRecord()` - The actual record data

## Next Steps

Now that you've built your first Signal, let's make it more useful.

### Add More Filtering

Track specific operations only:

```php
use SocialDept\AtpSignals\Enums\SignalCommitOperation;

public function operations(): ?array
{
    return [SignalCommitOperation::Create]; // Only new posts, not edits
}
```

[Learn more about filtering →](filtering.md)

### Process Events Asynchronously

For expensive operations, use Laravel queues:

```php
public function shouldQueue(): bool
{
    return true;
}

public function handle(SignalEvent $event): void
{
    // This now runs in a background job
    $this->performExpensiveAnalysis($event);
}
```

[Learn more about queues →](queues.md)

### Store Data

Let's store posts in your database:

```php
use App\Models\Post;

public function handle(SignalEvent $event): void
{
    $record = $event->getRecord();

    Post::updateOrCreate(
        [
            'did' => $event->did,
            'rkey' => $event->commit->rkey,
        ],
        [
            'text' => $record->text ?? null,
            'created_at' => $record->createdAt,
        ]
    );
}
```

### Handle Multiple Collections

Use wildcards to match multiple collections:

```php
public function collections(): ?array
{
    return [
        'app.bsky.feed.*', // All feed events
    ];
}

public function handle(SignalEvent $event): void
{
    $collection = $event->getCollection();

    match ($collection) {
        'app.bsky.feed.post' => $this->handlePost($event),
        'app.bsky.feed.like' => $this->handleLike($event),
        'app.bsky.feed.repost' => $this->handleRepost($event),
        default => null,
    };
}
```

## Building Something Real

Let's build a simple engagement tracker:

```php
<?php

namespace App\Signals;

use App\Models\EngagementMetric;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class EngagementTrackerSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return [
            'app.bsky.feed.post',
            'app.bsky.feed.like',
            'app.bsky.feed.repost',
        ];
    }

    public function operations(): ?array
    {
        return [SignalCommitOperation::Create];
    }

    public function shouldQueue(): bool
    {
        return true; // Process in background
    }

    public function handle(SignalEvent $event): void
    {
        EngagementMetric::create([
            'date' => now()->toDateString(),
            'collection' => $event->getCollection(),
            'event_type' => 'create',
            'count' => 1,
        ]);
    }
}
```

This Signal tracks all engagement activity (posts, likes, reposts) and stores metrics for analysis.

## Testing Your Signal

Before running in production, test your Signal with sample data:

```bash
php artisan signal:test NewPostSignal
```

This will run your Signal with a sample event and show you the output.

[Learn more about testing →](testing.md)

## Common Patterns

### Only Process Specific Users

```php
public function dids(): ?array
{
    return [
        'did:plc:z72i7hdynmk6r22z27h6tvur', // Specific user
    ];
}
```

### Add Custom Filtering Logic

```php
public function shouldHandle(SignalEvent $event): bool
{
    $record = $event->getRecord();

    // Only handle posts with images
    return isset($record->embed);
}
```

### Handle Failures Gracefully

```php
public function failed(SignalEvent $event, \Throwable $exception): void
{
    Log::error('Signal processing failed', [
        'event' => $event->toArray(),
        'error' => $exception->getMessage(),
    ]);

    // Optionally notify admins, store for retry, etc.
}
```

## Running in Production

### Using Supervisor

For production, run Signal under a process monitor like Supervisor:

```ini
[program:signal-consumer]
process_name=%(program_name)s
command=php /path/to/artisan signal:consume
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/logs/signal-consumer.log
```

### Starting from Last Position

Signal automatically saves cursor positions, so it resumes from where it left off:

```bash
php artisan signal:consume
```

To start fresh and ignore stored position:

```bash
php artisan signal:consume --fresh
```

To start from a specific cursor:

```bash
php artisan signal:consume --cursor=123456789
```

### Troubleshooting Missing Events

If you're not seeing expected events, you may have a stale cursor from a previous session. Use the `--fresh` flag to start from the current position:

```bash
php artisan signal:consume --fresh
```

This is especially useful when:
- You've added new collection filters to your Signals
- You're testing with a newly created Signal
- Events seem to be missing or delayed

## What's Next?

You now know the basics of building Signals! Explore more advanced topics:

- **[Signal Architecture](signals.md)** - Deep dive into Signal structure
- **[Advanced Filtering](filtering.md)** - Master collection patterns and wildcards
- **[Jetstream vs Firehose](modes.md)** - Choose the right mode for your use case
- **[Queue Integration](queues.md)** - Build high-performance processors
- **[Real-World Examples](examples.md)** - Learn from production use cases

## Getting Help

- Check the [examples documentation](examples.md) for more patterns
- Review the [configuration guide](configuration.md) for all options
- Open an issue on GitHub if you encounter problems
