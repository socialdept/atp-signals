# Creating Signals

Signals are the heart of the Signal package. They define how your application responds to AT Protocol events.

## What is a Signal?

A **Signal** is a PHP class that:

1. Listens for specific types of AT Protocol events
2. Filters those events based on your criteria
3. Executes custom logic when matching events arrive

Think of Signals like Laravel event listeners, but specifically designed for the AT Protocol.

## Basic Signal Structure

Every Signal extends the base `Signal` class:

```php
<?php

namespace App\Signals;

use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class MySignal extends Signal
{
    /**
     * Define which event types to listen for.
     * Required.
     */
    public function eventTypes(): array
    {
        return ['commit'];
    }

    /**
     * Handle the event when it arrives.
     * Required.
     */
    public function handle(SignalEvent $event): void
    {
        // Your logic here
    }
}
```

Only two methods are required:
- `eventTypes()` - Which event types to listen for
- `handle()` - What to do when events arrive

## Creating Signals

### Using Artisan (Recommended)

Generate a new Signal with the make command:

```bash
php artisan make:signal MySignal
```

This creates `app/Signals/MySignal.php` with a basic template.

#### With Options

Generate a Signal with pre-configured filters:

```bash
# Create a Signal for posts only
php artisan make:signal PostSignal --type=commit --collection=app.bsky.feed.post

# Create a Signal for follows
php artisan make:signal FollowSignal --type=commit --collection=app.bsky.graph.follow
```

### Manual Creation

You can also create Signals manually in `app/Signals/`:

```php
<?php

namespace App\Signals;

use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class ManualSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function handle(SignalEvent $event): void
    {
        //
    }
}
```

Signals are automatically discovered from `app/Signals/` - no registration needed.

## Event Types

Signals can listen for three types of AT Protocol events:

### Commit Events

Repository commits represent changes to user data:

```php
use SocialDept\AtpSignals\Enums\SignalEventType;

public function eventTypes(): array
{
    return [SignalEventType::Commit];
    // Or: return ['commit'];
}
```

**Common commit events:**
- Creating posts, likes, follows, reposts
- Updating profile information
- Deleting content

This is the most common event type and what you'll use 99% of the time.

### Identity Events

Identity changes track handle updates:

```php
public function eventTypes(): array
{
    return [SignalEventType::Identity];
    // Or: return ['identity'];
}
```

**Use cases:**
- Tracking handle changes
- Updating local user records
- Monitoring account migrations

### Account Events

Account status changes track account state:

```php
public function eventTypes(): array
{
    return [SignalEventType::Account];
    // Or: return ['account'];
}
```

**Use cases:**
- Detecting account deactivation
- Monitoring account status
- Compliance tracking

### Multiple Event Types

Listen to multiple event types in one Signal:

```php
public function eventTypes(): array
{
    return [
        SignalEventType::Commit,
        SignalEventType::Identity,
    ];
}

public function handle(SignalEvent $event): void
{
    if ($event->isCommit()) {
        // Handle commit
    }

    if ($event->isIdentity()) {
        // Handle identity change
    }
}
```

## The SignalEvent Object

The `SignalEvent` object contains all event data:

### Common Properties

```php
public function handle(SignalEvent $event): void
{
    // User's DID (decentralized identifier)
    $did = $event->did; // "did:plc:z72i7hdynmk6r22z27h6tvur"

    // Event type (commit, identity, account)
    $kind = $event->kind;

    // Timestamp in microseconds
    $timestamp = $event->timeUs;

    // Convert to Carbon instance
    $date = $event->getTimestamp();
}
```

### Commit Events

For commit events, access the `commit` property:

```php
public function handle(SignalEvent $event): void
{
    if ($event->isCommit()) {
        // Collection (e.g., "app.bsky.feed.post")
        $collection = $event->commit->collection;
        // Or: $collection = $event->getCollection();

        // Operation (create, update, delete)
        $operation = $event->commit->operation;
        // Or: $operation = $event->getOperation();

        // Record key (unique identifier)
        $rkey = $event->commit->rkey;

        // Revision
        $rev = $event->commit->rev;

        // The actual record data
        $record = $event->commit->record;
        // Or: $record = $event->getRecord();
    }
}
```

### Working with Records

Records contain the actual data (posts, likes, etc.):

```php
public function handle(SignalEvent $event): void
{
    $record = $event->getRecord();

    // For posts (app.bsky.feed.post)
    $text = $record->text ?? null;
    $createdAt = $record->createdAt ?? null;
    $embed = $record->embed ?? null;
    $facets = $record->facets ?? null;

    // For likes (app.bsky.feed.like)
    $subject = $record->subject ?? null;

    // For follows (app.bsky.graph.follow)
    $subject = $record->subject ?? null;
}
```

Records are `stdClass` objects, so use null coalescing (`??`) for safety.

### Identity Events

For identity events, access the `identity` property:

```php
public function handle(SignalEvent $event): void
{
    if ($event->isIdentity()) {
        // New handle
        $handle = $event->identity->handle;

        // User's DID
        $did = $event->did;

        // Sequence number
        $seq = $event->identity->seq;

        // Timestamp
        $time = $event->identity->time;
    }
}
```

### Account Events

For account events, access the `account` property:

```php
public function handle(SignalEvent $event): void
{
    if ($event->isAccount()) {
        // Account status
        $active = $event->account->active; // true/false

        // Status reason
        $status = $event->account->status ?? null;

        // User's DID
        $did = $event->did;

        // Sequence number
        $seq = $event->account->seq;

        // Timestamp
        $time = $event->account->time;
    }
}
```

## Helper Methods

Signals provide several helper methods for common tasks:

### Type Checking

```php
public function handle(SignalEvent $event): void
{
    // Check event type
    if ($event->isCommit()) {
        //
    }

    if ($event->isIdentity()) {
        //
    }

    if ($event->isAccount()) {
        //
    }
}
```

### Operation Checking (Commit Events)

```php
use SocialDept\AtpSignals\Enums\SignalCommitOperation;

public function handle(SignalEvent $event): void
{
    $operation = $event->getOperation();

    // Using enum
    if ($operation === SignalCommitOperation::Create) {
        // Handle new records
    }

    // Using commit helper
    if ($event->commit->isCreate()) {
        // Handle new records
    }

    if ($event->commit->isUpdate()) {
        // Handle updates
    }

    if ($event->commit->isDelete()) {
        // Handle deletions
    }
}
```

### Data Extraction

```php
public function handle(SignalEvent $event): void
{
    // Get collection (commit events only)
    $collection = $event->getCollection();

    // Get operation (commit events only)
    $operation = $event->getOperation();

    // Get record (commit events only)
    $record = $event->getRecord();

    // Get timestamp as Carbon
    $timestamp = $event->getTimestamp();

    // Convert to array
    $array = $event->toArray();
}
```

## Optional Signal Methods

Signals support several optional methods for advanced behavior:

### Collections Filter

Filter by AT Protocol collections:

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}
```

Return `null` to handle all collections.

[Learn more about collection filtering →](filtering.md)

### Operations Filter

Filter by operation type (commit events only):

```php
public function operations(): ?array
{
    return [SignalCommitOperation::Create];
}
```

Return `null` to handle all operations.

[Learn more about operation filtering →](filtering.md)

### DIDs Filter

Filter by specific users:

```php
public function dids(): ?array
{
    return [
        'did:plc:z72i7hdynmk6r22z27h6tvur',
    ];
}
```

Return `null` to handle all users.

[Learn more about DID filtering →](filtering.md)

### Custom Filtering

Add complex filtering logic:

```php
public function shouldHandle(SignalEvent $event): bool
{
    // Only handle posts with images
    if ($event->isCommit() && $event->getCollection() === 'app.bsky.feed.post') {
        $record = $event->getRecord();
        return isset($record->embed);
    }

    return true;
}
```

### Queue Configuration

Process events asynchronously:

```php
public function shouldQueue(): bool
{
    return true;
}

public function queue(): string
{
    return 'high-priority';
}

public function queueConnection(): string
{
    return 'redis';
}
```

[Learn more about queue integration →](queues.md)

### Failure Handling

Handle processing failures:

```php
public function failed(SignalEvent $event, \Throwable $exception): void
{
    Log::error('Signal failed', [
        'signal' => static::class,
        'event' => $event->toArray(),
        'error' => $exception->getMessage(),
    ]);
}
```

## Signal Lifecycle

Understanding the Signal lifecycle helps you write better Signals:

### 1. Event Arrives

An event arrives from the AT Protocol (via Jetstream, Firehose, or an Obelisk archive).

### 2. Event Type Matching

Signal checks if the event type matches your `eventTypes()` definition.

### 3. Collection Filtering

If defined, Signal checks if the collection matches your `collections()` definition.

### 4. Operation Filtering

If defined, Signal checks if the operation matches your `operations()` definition.

### 5. DID Filtering

If defined, Signal checks if the DID matches your `dids()` definition.

### 6. Custom Filtering

If defined, Signal calls your `shouldHandle()` method.

### 7. Queue Decision

Signal checks `shouldQueue()` to determine if the event should be queued.

### 8. Handler Execution

Your `handle()` method is called (either synchronously or via queue).

### 9. Failure Handling (if applicable)

If an exception occurs, your `failed()` method is called (if defined).

## Best Practices

### Keep Handlers Focused

Each Signal should do one thing well:

```php
// Good - focused on one task
class TrackNewPostsSignal extends Signal
{
    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function handle(SignalEvent $event): void
    {
        $this->storePost($event);
    }
}

// Less ideal - doing too much
class MonitorEverythingSignal extends Signal
{
    public function handle(SignalEvent $event): void
    {
        $this->storePost($event);
        $this->sendNotification($event);
        $this->updateAnalytics($event);
        $this->processRecommendations($event);
    }
}
```

### Use Queues for Heavy Work

Don't block the consumer with expensive operations:

```php
class AnalyzePostSignal extends Signal
{
    public function shouldQueue(): bool
    {
        return true; // Process in background
    }

    public function handle(SignalEvent $event): void
    {
        $this->performExpensiveAnalysis($event);
    }
}
```

### Validate Data Safely

Records can have missing or unexpected data:

```php
public function handle(SignalEvent $event): void
{
    $record = $event->getRecord();

    // Use null coalescing
    $text = $record->text ?? '';

    // Validate before processing
    if (empty($text)) {
        return;
    }

    // Safe to process
    $this->processText($text);
}
```

### Add Logging

Log important events for debugging:

```php
public function handle(SignalEvent $event): void
{
    Log::debug('Processing event', [
        'signal' => static::class,
        'collection' => $event->getCollection(),
        'operation' => $event->getOperation()->value,
    ]);

    // Your logic
}
```

### Handle Failures Gracefully

Always implement failure handling for queued Signals:

```php
public function failed(SignalEvent $event, \Throwable $exception): void
{
    Log::error('Signal processing failed', [
        'signal' => static::class,
        'event_did' => $event->did,
        'error' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);

    // Optionally: send to error tracking service
    // report($exception);
}
```

## Auto-Discovery

Signals are automatically discovered from `app/Signals/` by default. You can customize discovery in `config/signal.php`:

```php
'auto_discovery' => [
    'enabled' => true,
    'path' => app_path('Signals'),
    'namespace' => 'App\\Signals',
],
```

### Manual Registration

Disable auto-discovery and register Signals manually:

```php
'auto_discovery' => [
    'enabled' => false,
],

'signals' => [
    \App\Signals\NewPostSignal::class,
    \App\Signals\NewFollowSignal::class,
],
```

## Testing Signals

Test your Signals before deploying:

```bash
php artisan signal:test MySignal
```

[Learn more about testing →](testing.md)

## Listing Signals

View all registered Signals:

```bash
php artisan signal:list
```

This displays:
- Signal class names
- Event types they listen for
- Collection filters (if any)
- Queue configuration

## Next Steps

- **[Learn about filtering →](filtering.md)** - Master collection patterns and wildcards
- **[Understand queue integration →](queues.md)** - Build high-performance processors
- **[See real-world examples →](examples.md)** - Learn from production use cases
