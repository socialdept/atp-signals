# Creating Signals

Part of the [atp-signals docs](../introduction.md#documentation). The Signal class, the events it receives and the methods it can override.

## What is a Signal?

A **Signal** is a PHP class that:

1. Listens for specific types of AT Protocol events
2. Filters those events based on your criteria
3. Runs your logic when a matching event arrives

Signals work like Laravel event listeners for AT Protocol events.

## Basic Signal Structure

Every Signal extends the abstract `SocialDept\AtpSignals\Signals\Signal` class:

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

Only two methods are abstract and must be implemented:
- `eventTypes()`: which event types to listen for
- `handle()`: what to do when a matching event arrives

## Creating Signals

### Using Artisan

Generate a new Signal with the make command:

```bash
php artisan make:signal MySignal
```

This creates `app/Signals/MySignal.php` from the package stub. The generated class has `eventTypes()`, `collections()`, `handle()`, `shouldHandle()` and `shouldQueue()` methods. Without options, `eventTypes()` returns `['commit']` and `collections()` returns `['app.bsky.feed.post']`.

#### With Options

Set the event type and collection with `--type` (`-t`) and `--collection` (`-c`):

```bash
# Create a Signal for posts only
php artisan make:signal PostSignal --type=commit --collection=app.bsky.feed.post

# Create a Signal for follows
php artisan make:signal FollowSignal --type=commit --collection=app.bsky.graph.follow
```

The stub always writes a `collections()` method. For an `identity` or `account` Signal the collection filter is ignored, but you can delete the method.

### Manual Creation

You can also create Signals by hand in `app/Signals/`:

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

Signals in `app/Signals/` are discovered automatically, so no registration is needed.

## Event Types

Signals can listen for four event types, defined by the `SocialDept\AtpSignals\Enums\SignalEventType` enum. You can return enum cases or their string values.

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

### Identity Events

Identity events report changes to an account's identity, such as a handle change:

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

### Account Events

Account events report changes to an account's status:

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

### Sync Events

Sync events are only emitted by Jetstream v2. A sync event marks a repo divergence: the account's records can no longer be folded from the event stream alone and should be re-fetched from its PDS.

```php
public function eventTypes(): array
{
    return [SignalEventType::Sync];
    // Or: return ['sync'];
}
```

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

Every handler receives a `SocialDept\AtpSignals\Events\SignalEvent`.

### Common Properties

```php
public function handle(SignalEvent $event): void
{
    // User's DID (decentralized identifier)
    $did = $event->did; // "did:plc:z72i7hdynmk6r22z27h6tvur"

    // Event type: "commit", "identity", "account" or "sync"
    $kind = $event->kind;

    // Event time in microseconds
    $timestamp = $event->timeUs;
}
```

`timeUs` is a microsecond timestamp on Jetstream and Obelisk events. In firehose mode the consumer stores the firehose sequence number in `timeUs` instead, so do not read it as a time there.

A few properties are only set by some sources:

- `backfill` (`?bool`): set on Obelisk events, `null` on Jetstream and firehose
- `cursor` (`?string`): the Obelisk event id, `null` on Jetstream and firehose
- `seq` (`?int`): the Jetstream v2 cursor, `null` on other sources
- `sync` (`?SyncEvent`): the payload of a sync event (Jetstream v2 only)

### Commit Events

For commit events, the `commit` property holds a `SocialDept\AtpSignals\Events\CommitEvent`:

```php
public function handle(SignalEvent $event): void
{
    if ($event->isCommit()) {
        // Collection (e.g., "app.bsky.feed.post")
        $collection = $event->commit->collection;
        // Or: $collection = $event->getCollection();

        // Operation, a SignalCommitOperation enum (Create, Update, Delete)
        $operation = $event->commit->operation;
        // Or: $operation = $event->getOperation();

        // Record key
        $rkey = $event->commit->rkey;

        // Revision
        $rev = $event->commit->rev;

        // Record CID (may be null)
        $cid = $event->commit->cid;

        // The record data (null for deletes)
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
    $embed = $record->embed ?? null;   // array
    $facets = $record->facets ?? null; // array

    // For likes (app.bsky.feed.like) and follows (app.bsky.graph.follow)
    $subject = $record->subject ?? null;
}
```

A record is a `stdClass` object at the top level only. Nested values such as `embed`, `facets` and a like's `subject` are plain arrays, so read them with array syntax (`$record->subject['uri']`). Fields can be missing, so use null coalescing (`??`).

### Identity Events

For identity events, the `identity` property holds a `SocialDept\AtpSignals\Events\IdentityEvent`:

```php
public function handle(SignalEvent $event): void
{
    if ($event->isIdentity()) {
        // Handle (may be null)
        $handle = $event->identity->handle;

        // User's DID
        $did = $event->did;

        // Sequence number
        $seq = $event->identity->seq;

        // Timestamp string (may be null)
        $time = $event->identity->time;
    }
}
```

### Account Events

For account events, the `account` property holds a `SocialDept\AtpSignals\Events\AccountEvent`:

```php
public function handle(SignalEvent $event): void
{
    if ($event->isAccount()) {
        // Whether the account is active
        $active = $event->account->active; // true/false

        // Status reason (may be null)
        $status = $event->account->status;

        // User's DID
        $did = $event->did;

        // Sequence number
        $seq = $event->account->seq;

        // Timestamp string (may be null)
        $time = $event->account->time;
    }
}
```

### Sync Events

For sync events, the `sync` property holds a `SocialDept\AtpSignals\Events\SyncEvent` with `did`, `rev`, `seq` and `time`:

```php
public function handle(SignalEvent $event): void
{
    if ($event->isSync()) {
        $did = $event->sync->did;
        $rev = $event->sync->rev; // may be null
    }
}
```

## Helper Methods

`SignalEvent` and `CommitEvent` provide a few helpers.

### Type Checking

```php
public function handle(SignalEvent $event): void
{
    if ($event->isCommit()) {
        //
    }

    if ($event->isIdentity()) {
        //
    }

    if ($event->isAccount()) {
        //
    }

    if ($event->isSync()) {
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
    // Get collection (null for non-commit events)
    $collection = $event->getCollection();

    // Get operation (null for non-commit events)
    $operation = $event->getOperation();

    // Get record (null for non-commit events and deletes)
    $record = $event->getRecord();

    // Convert to array
    $array = $event->toArray();
}
```

## Optional Signal Methods

The base class provides defaults for these methods, so override only the ones you need.

### Collections Filter

Filter by AT Protocol collections (commit events only):

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}
```

Return `null` (the default) to handle all collections.

[Learn more about collection filtering](filtering.md#collection-filtering)

### Operations Filter

Filter by operation type (commit events only):

```php
public function operations(): ?array
{
    return [SignalCommitOperation::Create];
}
```

Return `null` (the default) to handle all operations.

[Learn more about operation filtering](filtering.md#operation-filtering)

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

Return `null` (the default) to handle all users.

[Learn more about DID filtering](filtering.md#did-filtering)

### Custom Filtering

Add your own filtering logic. The default returns `true`.

```php
public function shouldHandle(SignalEvent $event): bool
{
    // Only handle posts with an embed
    if ($event->isCommit() && $event->getCollection() === 'app.bsky.feed.post') {
        $record = $event->getRecord();
        return isset($record->embed);
    }

    return true;
}
```

### Queue Configuration

Process events on a queue:

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

By default `queue()` returns `config('atp-signals.queue.queue')` (env `SIGNAL_QUEUE`, default `signal`) and `queueConnection()` returns `config('atp-signals.queue.connection')` (env `SIGNAL_QUEUE_CONNECTION`, default `null`, the app's default connection).

[Learn more about queue integration](queues.md)

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

The default implementation does nothing.

## Signal Lifecycle

For each event, the dispatcher finds the matching Signals and then runs each one.

### 1. Event Arrives

An event arrives from Jetstream, the firehose or Obelisk.

### 2. Event Type Matching

The event's `kind` must be one of the values in `eventTypes()`.

### 3. Collection Filtering

If `collections()` is not `null` and the event is a commit, the collection must match one of the patterns.

### 4. Operation Filtering

If `operations()` is not `null` and the event is a commit, the operation must be one of the listed operations.

### 5. DID Filtering

If `dids()` is not `null`, the event's DID must be in the list. This applies to every event type.

### 6. Custom Filtering

`shouldHandle()` must return `true`.

### 7. Queue Decision

If `shouldQueue()` returns `true`, the Signal and event are dispatched as a `ProcessSignalJob` on the Signal's `queueConnection()` and `queue()`. Otherwise the Signal runs in the consumer process.

Steps 2 to 6 always run in the consumer process, including for queued Signals.

### 8. Handler Execution

`handle()` is called, either right away or by a queue worker.

### 9. Failure Handling

If a synchronous `handle()` throws, the dispatcher logs the error and calls `failed()`, then moves on to the next Signal. The consumer keeps running. For a queued Signal, the job calls `failed()` when Laravel marks the job as failed.

## Best Practices

### Keep Handlers Focused

Give each Signal one job:

```php
// Good: focused on one task
class TrackNewPostsSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function handle(SignalEvent $event): void
    {
        $this->storePost($event);
    }
}

// Less ideal: doing too much
class MonitorEverythingSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

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

Synchronous Signals run inside the consumer loop, so slow work holds up every event behind it:

```php
class AnalyzePostSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

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

### Check Data Before Using It

Records can have missing or unexpected data:

```php
public function handle(SignalEvent $event): void
{
    $record = $event->getRecord();

    // Use null coalescing
    $text = $record->text ?? '';

    // Check before processing
    if (empty($text)) {
        return;
    }

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
        'operation' => $event->getOperation()?->value,
    ]);

    // Your logic
}
```

Set `SIGNAL_DEBUG=true` to have the dispatcher log every dispatch (Signal, kind, collection, operation, DID and whether it was queued).

### Handle Failures

Implement `failed()` for queued Signals, since the consumer never sees their exceptions:

```php
public function failed(SignalEvent $event, \Throwable $exception): void
{
    Log::error('Signal processing failed', [
        'signal' => static::class,
        'event_did' => $event->did,
        'error' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);

    // Optionally: send to your error tracker
    // report($exception);
}
```

## Auto-Discovery

Signals are discovered from `app/Signals/` by default. You can change discovery in `config/atp-signals.php`:

```php
'auto_discovery' => [
    'enabled' => true,
    'path' => app_path('Signals'),
    'namespace' => 'App\\Signals',
],
```

Discovery scans the folder recursively, but it builds each class name as the namespace plus the file name. A Signal in a subfolder, with a sub-namespace, is not found. Register it manually instead.

### Manual Registration

List Signals in the `signals` key. To use only that list, disable auto-discovery:

```php
'auto_discovery' => [
    'enabled' => false,
],

'signals' => [
    \App\Signals\NewPostSignal::class,
    \App\Signals\NewFollowSignal::class,
],
```

Every class must extend `SocialDept\AtpSignals\Signals\Signal`, or registration throws an `InvalidArgumentException`. Do not list a Signal in `signals` that auto-discovery also finds, or it is registered twice.

## Testing Signals

Run a Signal against a sample event:

```bash
php artisan signal:test MySignal
```

[Learn more about testing](testing.md)

## Listing Signals

View all registered Signals:

```bash
php artisan signal:list
```

For each Signal this shows:
- The short and full class name
- The event types it listens for
- Its collection filter, or "All collections"
- Its DID filter, if any
- Its queue name, if it is queued

## Next Steps

- **[Learn about filtering](filtering.md)**: collection patterns and wildcards
- **[Understand queue integration](queues.md)**: running Signals on a queue
- **[See examples](../examples.md)**: complete Signals for common tasks
