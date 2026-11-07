# Quickstart Guide

Part of the [atp-signals docs](../introduction.md#documentation). Building a first Signal and consuming events with it.

## Prerequisites

Before starting, make sure you have:

- [Installed the package](installation.md) in your Laravel application
- Run `php artisan signal:install`
- Basic familiarity with Laravel

## Your First Signal

This Signal logs every new post created on Bluesky.

### Step 1: Generate a Signal

```bash
php artisan make:signal NewPostSignal
```

This creates `app/Signals/NewPostSignal.php`. The generated class listens for `commit` events on `app.bsky.feed.post`. Pass `--type` (`-t`) or `--collection` (`-c`) to start from a different event type or collection.

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

```bash
php artisan signal:consume
```

The command lists the Signals it found, each with its event types, collections and operations, then prints the mode, where it resumes from, and `Starting jetstream consumer... Press Ctrl+C to stop.` It keeps running until you stop it.

Connection status goes to your Laravel log rather than the console, for example `[Signal] Connected to Jetstream successfully`. Your Signal's log lines show up there too, one per new post.

If no Signals are registered, the command prints a warning and exits.

## Understanding What Just Happened

### Event Types

```php
public function eventTypes(): array
{
    return ['commit'];
}
```

This asks for **commit** events, which represent changes to repositories (creating posts, likes, follows, and so on).

Available event types (also available as the `SocialDept\AtpSignals\Enums\SignalEventType` enum):

- `commit`: repository commits (most common)
- `identity`: identity changes (handle updates)
- `account`: account status changes
- `sync`: repository sync events (Jetstream v2 only)

### Collections

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}
```

This filters to the **post** collection. Returning `null` (the default) receives commit events for every collection.

Common collections:

- `app.bsky.feed.post`: posts
- `app.bsky.feed.like`: likes
- `app.bsky.graph.follow`: follows
- `app.bsky.feed.repost`: reposts

[Learn more about filtering](../signals/filtering.md)

### Handler Method

```php
public function handle(SignalEvent $event): void
{
    $record = $event->getRecord();
    // Your logic here
}
```

This runs for each matching event. The `SignalEvent` gives you:

- `did`: the author's DID (decentralized identifier)
- `kind`: the event type (`commit`, `identity`, `account` or `sync`)
- `timeUs`: the event time in microseconds on Jetstream. On the firehose it holds the event's sequence number
- `commit`: a `CommitEvent` with `rev`, `operation`, `collection`, `rkey`, `record` and `cid`
- `getRecord()`: the record data, or `null` when there is none (deletes)
- `getCollection()` and `getOperation()`: shortcuts into `commit`

## Next Steps

### Add More Filtering

Track specific operations only:

```php
use SocialDept\AtpSignals\Enums\SignalCommitOperation;

public function operations(): ?array
{
    return [SignalCommitOperation::Create]; // Only new posts, not updates or deletes
}
```

[Learn more about filtering](../signals/filtering.md)

### Process Events Asynchronously

For expensive work, queue the Signal:

```php
public function shouldQueue(): bool
{
    return true;
}

public function handle(SignalEvent $event): void
{
    // This now runs in a queued job
    $this->performExpensiveAnalysis($event);
}
```

Queued Signals go to the `signal` queue by default, so run a worker for it (`php artisan queue:work --queue=signal`).

[Learn more about queues](../signals/queues.md)

### Store Data

Store posts in your database:

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
            'created_at' => $record->createdAt ?? null,
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
        'app.bsky.feed.*', // All feed collections
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

A simple engagement tracker:

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

This Signal records a row for every new post, like and repost.

## Testing Your Signal

Run your Signal against a sample event:

```bash
php artisan signal:test NewPostSignal
```

The command builds a sample `commit` event (a post created by `did:plc:sample123456789`), prints it as JSON, then calls `shouldHandle()` and `handle()` on your Signal directly. It does not apply `eventTypes()`, `collections()`, `operations()` or `dids()`, and it does not queue.

[Learn more about testing](../signals/testing.md)

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

`shouldHandle()` runs after the other filters:

```php
public function shouldHandle(SignalEvent $event): bool
{
    $record = $event->getRecord();

    // Only handle posts with an embed (images, links, quotes)
    return isset($record->embed);
}
```

### Handle Failures

`failed()` is called when `handle()` throws on a synchronous Signal, and when the queued job fails for a queued Signal:

```php
public function failed(SignalEvent $event, \Throwable $exception): void
{
    Log::error('Signal processing failed', [
        'event' => $event->toArray(),
        'error' => $exception->getMessage(),
    ]);
}
```

For a synchronous Signal the error is also logged and the consumer moves on to the next Signal and event.

## Running in Production

### Using Supervisor

Run the consumer under a process monitor like Supervisor:

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

The consumer reconnects on its own after a dropped connection and exits with an error once it runs out of attempts, so `autorestart` matters. See [connection settings](configuration.md#connection).

### Starting from Last Position

The consumer saves its cursor as events arrive, so it resumes from where it left off:

```bash
php artisan signal:consume
```

To ignore the stored position:

```bash
php artisan signal:consume --fresh
```

On Jetstream and the firehose this connects without a cursor and starts from live events. In `obelisk` mode it reads the archive's event log from the start. The stored cursor is not deleted, it is overwritten as new events arrive.

To start from a specific cursor:

```bash
php artisan signal:consume --cursor=123456789
```

The value is a `time_us` timestamp on Jetstream v1, a sequence number on Jetstream v2 and the firehose, and an event id on Obelisk.

### Catching Up After Downtime

When the consumer has been stopped for a while, it resumes from the stored cursor and replays everything since then before it reaches live events, so new events can look delayed. If you do not need the backlog, start with `--fresh`.

## What's Next?

- **[Signal Architecture](../signals/signals.md)**: how a Signal is structured
- **[Filtering](../signals/filtering.md)**: collection patterns and wildcards
- **[Jetstream vs Firehose](../sources/modes.md)**: choose a mode for your use case
- **[Queue Integration](../signals/queues.md)**: run Signals on queues
- **[Examples](../examples.md)**: complete Signals for common tasks

## Getting Help

- Check the [examples](../examples.md) for more patterns
- Review the [configuration guide](configuration.md) for all options
- Open an issue on GitHub if you run into problems
