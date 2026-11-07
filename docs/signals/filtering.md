# Filtering Events

Part of the [atp-signals docs](../introduction.md#documentation). Choosing which events a Signal handles, by event type, collection, operation, DID or custom logic.

## Why Filter?

The network produces a constant stream of events, and most of them are irrelevant to any one app. Filtering keeps your Signals, database and queues limited to the events you care about. In Jetstream mode, collection filters also reduce what the server sends you.

## Filter Layers

For every event, each Signal's filters are checked in this order. The first one that fails skips the Signal.

1. **Event type**: which kind of event (commit, identity, account, sync)
2. **Collection**: which AT Protocol collections (commit events only)
3. **Operation**: which operations, create, update or delete (commit events only)
4. **DID**: which users (all event types)
5. **Custom**: your own logic in `shouldHandle()`

## Event Type Filtering

The only required filter.

### Available Event Types

```php
use SocialDept\AtpSignals\Enums\SignalEventType;

public function eventTypes(): array
{
    return [SignalEventType::Commit];
    // Or: return ['commit'];
}
```

**Four event types:**

| Type       | Description                         | Use Cases                                  |
|------------|-------------------------------------|--------------------------------------------|
| `commit`   | Repository changes                  | Posts, likes, follows, profile updates     |
| `identity` | Identity changes                    | Handle updates                             |
| `account`  | Account status                      | Deactivation, suspension                   |
| `sync`     | Repo divergence (Jetstream v2 only) | Re-fetch an account's records from its PDS |

### Multiple Event Types

Listen to multiple types in one Signal:

```php
public function eventTypes(): array
{
    return [
        SignalEventType::Commit,
        SignalEventType::Identity,
    ];
}
```

Then check the type in your handler:

```php
public function handle(SignalEvent $event): void
{
    if ($event->isCommit()) {
        $this->handleCommit($event);
    }

    if ($event->isIdentity()) {
        $this->handleIdentity($event);
    }
}
```

## Collection Filtering

Collections represent different types of data in the AT Protocol. The collection filter only applies to commit events. Identity, account and sync events pass it regardless.

### Basic Collection Filter

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}
```

### No Filter (All Collections)

Return `null` to process all collections:

```php
public function collections(): ?array
{
    return null; // Handle everything
}
```

### Multiple Collections

```php
public function collections(): ?array
{
    return [
        'app.bsky.feed.post',
        'app.bsky.feed.like',
        'app.bsky.feed.repost',
    ];
}
```

### Wildcard Patterns

Use `*` to match multiple collections:

```php
public function collections(): ?array
{
    return ['app.bsky.feed.*'];
}
```

**This matches:**
- `app.bsky.feed.post`
- `app.bsky.feed.like`
- `app.bsky.feed.repost`
- Any other `app.bsky.feed.*` collection

A `*` matches any run of characters, including dots, so `app.bsky.*` also matches `app.bsky.feed.post`. Matching is case-sensitive and covers the whole collection name. Patterns without `*` must match exactly.

### Common Collection Patterns

| Pattern              | Matches                 | Use Case               |
|----------------------|-------------------------|------------------------|
| `app.bsky.feed.*`    | All feed records        | Posts, likes, reposts  |
| `app.bsky.graph.*`   | All social graph        | Follows, blocks, lists |
| `app.bsky.actor.*`   | Actor records           | Profile updates        |
| `app.bsky.*`         | All Bluesky collections | Everything Bluesky     |
| `com.example.*`      | Your custom collections | Custom AppView         |

### Mixing Exact and Wildcards

Combine exact matches with wildcards:

```php
public function collections(): ?array
{
    return [
        'app.bsky.feed.post',        // Exact: only posts
        'app.bsky.graph.*',          // Wildcard: all graph records
        'com.example.custom.record', // Exact: custom collection
    ];
}
```

### Standard Bluesky Collections

**Feed Collections** (`app.bsky.feed.*`):
- `app.bsky.feed.post`: posts
- `app.bsky.feed.like`: likes on posts
- `app.bsky.feed.repost`: reposts
- `app.bsky.feed.threadgate`: thread reply controls
- `app.bsky.feed.generator`: custom feed generators

**Graph Collections** (`app.bsky.graph.*`):
- `app.bsky.graph.follow`: follow relationships
- `app.bsky.graph.block`: blocked users
- `app.bsky.graph.list`: user lists
- `app.bsky.graph.listitem`: list memberships
- `app.bsky.graph.listblock`: list blocks

**Actor Collections** (`app.bsky.actor.*`):
- `app.bsky.actor.profile`: user profiles

**Labeler Collections** (`app.bsky.labeler.*`):
- `app.bsky.labeler.service`: labeler services

### Jetstream vs Firehose Filtering

**Jetstream mode:**
- The consumer collects the patterns from every registered Signal and sends them to the server as collection filters, wildcards included, so the server only sends matching commits.
- If any Signal returns `null` from `collections()`, no collection filter is sent and you receive every commit.
- Jetstream accepts prefix wildcards that end in `.*`. Keep patterns to that form in Jetstream mode.
- In Jetstream v2, the consumer also sends the event types your Signals listen for, so the server skips the other kinds.

**Firehose mode:**
- Nothing is filtered on the server. Every event is received and decoded, and all filtering happens in your app.

In every mode, the package applies each Signal's filters again in your app before running it, so a Signal only sees events that match its own filters.

[Learn more about modes](../sources/modes.md)

### Custom Collections (AppViews)

Filter your own custom collections:

```php
public function collections(): ?array
{
    return [
        'com.example.publication',
        'com.example.post',
    ];
}
```

## Operation Filtering

Filter by operation type. This only applies to commit events.

### Available Operations

```php
use SocialDept\AtpSignals\Enums\SignalCommitOperation;

public function operations(): ?array
{
    return [SignalCommitOperation::Create];
    // Or: return ['create'];
}
```

**Three operation types:**

| Operation | Description      | Example         |
|-----------|------------------|-----------------|
| `create`  | New records      | Creating a post |
| `update`  | Modified records | Editing a post  |
| `delete`  | Removed records  | Deleting a post |

### No Filter (All Operations)

```php
public function operations(): ?array
{
    return null; // Handle all operations
}
```

### Multiple Operations

```php
public function operations(): ?array
{
    return [
        SignalCommitOperation::Create,
        SignalCommitOperation::Update,
    ];
    // Or: return ['create', 'update'];
}
```

### Common Patterns

**Only track new content:**
```php
public function operations(): ?array
{
    return [SignalCommitOperation::Create];
}
```

**Track modifications:**
```php
public function operations(): ?array
{
    return [SignalCommitOperation::Update];
}
```

**Clean up on deletions:**
```php
public function operations(): ?array
{
    return [SignalCommitOperation::Delete];
}
```

### Checking Operations in Handler

You can also check the operation in your handler:

```php
public function handle(SignalEvent $event): void
{
    $operation = $event->getOperation();

    // Using enum
    if ($operation === SignalCommitOperation::Create) {
        $this->createRecord($event);
    }

    // Using commit helpers
    if ($event->commit->isCreate()) {
        $this->createRecord($event);
    }

    if ($event->commit->isUpdate()) {
        $this->updateRecord($event);
    }

    if ($event->commit->isDelete()) {
        $this->deleteRecord($event);
    }
}
```

## DID Filtering

Filter events by specific users (DIDs). Unlike the collection and operation filters, the DID filter applies to every event type. It is always checked in your app and never sent to the server.

### Basic DID Filter

```php
public function dids(): ?array
{
    return [
        'did:plc:z72i7hdynmk6r22z27h6tvur',
    ];
}
```

### No Filter (All Users)

```php
public function dids(): ?array
{
    return null; // Handle all users
}
```

### Multiple DIDs

```php
public function dids(): ?array
{
    return [
        'did:plc:z72i7hdynmk6r22z27h6tvur',
        'did:plc:ragtjsm2j2vknwkz3zp4oxrd',
    ];
}
```

### Use Cases

**Monitor specific accounts:**
```php
// Track posts from specific accounts
public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}

public function dids(): ?array
{
    return [
        'did:plc:z72i7hdynmk6r22z27h6tvur', // Account 1
        'did:plc:ragtjsm2j2vknwkz3zp4oxrd', // Account 2
    ];
}
```

**Dynamic DID filtering:**

`dids()` is called for every event that reaches the DID check, so cache a list that comes from the database:

```php
use App\Models\MonitoredAccount;
use Illuminate\Support\Facades\Cache;

public function dids(): ?array
{
    return Cache::remember('monitored-dids', 60, fn () => MonitoredAccount::pluck('did')->all());
}
```

## Custom Filtering

Implement any other filtering logic in `shouldHandle()`. It runs after all the other filters pass.

### Basic Custom Filter

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

### More Examples

**Filter by text content:**
```php
public function shouldHandle(SignalEvent $event): bool
{
    $record = $event->getRecord();

    if (! isset($record->text)) {
        return false;
    }

    // Only handle posts mentioning "Laravel"
    return str_contains($record->text, 'Laravel');
}
```

**Filter by language:**
```php
public function shouldHandle(SignalEvent $event): bool
{
    $record = $event->getRecord();

    // Only handle posts whose first language is English
    return ($record->langs[0] ?? null) === 'en';
}
```

## Combining Filters

Stack filter layers for precise targeting:

```php
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class LongMediaPostsSignal extends Signal
{
    // Layer 1: Event type
    public function eventTypes(): array
    {
        return ['commit'];
    }

    // Layer 2: Collection
    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    // Layer 3: Operation
    public function operations(): ?array
    {
        return [SignalCommitOperation::Create];
    }

    // Layer 5: Custom logic
    public function shouldHandle(SignalEvent $event): bool
    {
        $record = $event->getRecord();

        // Must have text
        if (! isset($record->text)) {
            return false;
        }

        // Must be at least 100 characters
        if (strlen($record->text) < 100) {
            return false;
        }

        // Must have media
        if (! isset($record->embed)) {
            return false;
        }

        return true;
    }

    public function handle(SignalEvent $event): void
    {
        // Only long posts with media make it here
    }
}
```

## Performance Considerations

### Prefer Declarative Filters

Use `eventTypes()`, `collections()` and `operations()` rather than doing the same checks in `shouldHandle()`. In Jetstream mode the collections are sent to the server, which a check in `shouldHandle()` cannot do.

```php
// Good: declarative filters
public function eventTypes(): array
{
    return ['commit'];
}

public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}

// Less ideal: everything in custom logic
public function eventTypes(): array
{
    return ['commit', 'identity', 'account'];
}

public function shouldHandle(SignalEvent $event): bool
{
    return $event->isCommit() && $event->getCollection() === 'app.bsky.feed.post';
}
```

The second version also leaves `collections()` as `null`, which turns off the Jetstream server filter for every Signal.

### Keep shouldHandle() Light

All filters, `shouldHandle()` included, run in the consumer process for every event, even when the Signal is queued:

```php
// Good: lightweight checks
public function shouldHandle(SignalEvent $event): bool
{
    $record = $event->getRecord();
    return isset($record->text) && strlen($record->text) > 10;
}

// Less ideal: a database query on every event
public function shouldHandle(SignalEvent $event): bool
{
    return User::where('did', $event->did)->exists();
}
```

If a check is expensive, queue the Signal and do the check at the start of `handle()`, which then runs on a queue worker:

```php
public function shouldQueue(): bool
{
    return true;
}

public function handle(SignalEvent $event): void
{
    if (! User::where('did', $event->did)->exists()) {
        return;
    }

    // ...
}
```

## Common Filter Patterns

### Track All Activity from Specific Users

```php
public function eventTypes(): array
{
    return ['commit'];
}

public function dids(): ?array
{
    return [
        'did:plc:z72i7hdynmk6r22z27h6tvur',
    ];
}
```

### Monitor All Feed Activity

```php
public function eventTypes(): array
{
    return ['commit'];
}

public function collections(): ?array
{
    return ['app.bsky.feed.*'];
}
```

### Track Only New Posts

```php
public function eventTypes(): array
{
    return ['commit'];
}

public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}

public function operations(): ?array
{
    return [SignalCommitOperation::Create];
}
```

### Monitor Content Deletions

```php
public function eventTypes(): array
{
    return ['commit'];
}

public function operations(): ?array
{
    return [SignalCommitOperation::Delete];
}
```

### Track Profile Changes

```php
public function eventTypes(): array
{
    return ['commit'];
}

public function collections(): ?array
{
    return ['app.bsky.actor.profile'];
}
```

### Monitor Handle Changes

```php
public function eventTypes(): array
{
    return ['identity'];
}
```

## Debugging Filters

### Log What Is Filtered Out

```php
public function shouldHandle(SignalEvent $event): bool
{
    $shouldHandle = $this->myCustomLogic($event);

    if (! $shouldHandle) {
        Log::debug('Event filtered out', [
            'signal' => static::class,
            'did' => $event->did,
            'collection' => $event->getCollection(),
        ]);
    }

    return $shouldHandle;
}
```

Set `SIGNAL_DEBUG=true` to log every event the dispatcher sends to a Signal, which shows what passed your filters.

### Test Your Filters

```bash
php artisan signal:test YourSignal
```

This builds a sample commit event (a new `app.bsky.feed.post` record), calls your Signal's `shouldHandle()`, and calls `handle()` if it returns `true`. It does not check `eventTypes()`, `collections()`, `operations()` or `dids()`.

[Learn more about testing](testing.md)

## Next Steps

- **[Understand Jetstream vs Firehose](../sources/modes.md)**: choose the right mode for your filters
- **[Learn about queue integration](queues.md)**: handle high-volume filtered events
- **[See examples](../examples.md)**: complete Signals for common tasks
