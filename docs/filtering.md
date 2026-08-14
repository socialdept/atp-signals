# Filtering Events

Filtering is how you control which events your Signals process. Signal provides multiple layers of filtering to help you target exactly the events you care about.

## Why Filter?

The AT Protocol generates millions of events per hour. Without filtering:

- Your Signals would process every event (slow and expensive)
- Your database would fill with irrelevant data
- Your queues would be overwhelmed
- Your costs would skyrocket

Filtering lets you focus on what matters.

## Filter Layers

Signal provides four filtering layers, applied in order:

1. **Event Type Filtering** - Which kind of events (commit, identity, account, sync)
2. **Collection Filtering** - Which AT Protocol collections
3. **Operation Filtering** - Which operations (create, update, delete)
4. **DID Filtering** - Which users
5. **Custom Filtering** - Your own logic

## Event Type Filtering

The most basic filter - required for all Signals.

### Available Event Types

```php
use SocialDept\AtpSignals\Enums\SignalEventType;

public function eventTypes(): array
{
    return [SignalEventType::Commit]; // Most common
    // Or: return ['commit'];
}
```

**Four event types:**

| Type       | Description        | Use Cases                              |
|------------|--------------------|----------------------------------------|
| `commit`   | Repository changes | Posts, likes, follows, profile updates |
| `identity` | Handle changes     | Username updates, account migrations   |
| `account`  | Account status     | Deactivation, suspension               |
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

Collections represent different types of data in the AT Protocol.

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

### Common Collection Patterns

| Pattern            | Matches                 | Use Case               |
|--------------------|-------------------------|------------------------|
| `app.bsky.feed.*`  | All feed interactions   | Posts, likes, reposts  |
| `app.bsky.graph.*` | All social graph        | Follows, blocks, mutes |
| `app.bsky.actor.*` | All profile changes     | Profile updates        |
| `app.bsky.*`       | All Bluesky collections | Everything Bluesky     |
| `app.yourapp.*`    | Your custom collections | Custom AppView         |

### Mixing Exact and Wildcards

Combine exact matches with wildcards:

```php
public function collections(): ?array
{
    return [
        'app.bsky.feed.post',        // Exact: only posts
        'app.bsky.graph.*',          // Wildcard: all graph events
        'app.myapp.custom.record',   // Exact: custom collection
    ];
}
```

### Standard Bluesky Collections

**Feed Collections** (`app.bsky.feed.*`):
- `app.bsky.feed.post` - Posts (text, images, videos)
- `app.bsky.feed.like` - Likes on posts
- `app.bsky.feed.repost` - Reposts (shares)
- `app.bsky.feed.threadgate` - Thread reply controls
- `app.bsky.feed.generator` - Custom feed generators

**Graph Collections** (`app.bsky.graph.*`):
- `app.bsky.graph.follow` - Follow relationships
- `app.bsky.graph.block` - Blocked users
- `app.bsky.graph.list` - User lists
- `app.bsky.graph.listitem` - List memberships
- `app.bsky.graph.listblock` - List blocks

**Actor Collections** (`app.bsky.actor.*`):
- `app.bsky.actor.profile` - User profiles

**Labeler Collections** (`app.bsky.labeler.*`):
- `app.bsky.labeler.service` - Labeler services

### Important: Jetstream vs Firehose Filtering

**Jetstream Mode:**
- Exact collection names are sent to server for filtering (efficient)
- Wildcards work client-side only (you receive more data)

**Firehose Mode:**
- All filtering is client-side
- Wildcards work normally (no difference in data received)

[Learn more about modes →](modes.md)

### Custom Collections (AppViews)

Filter your own custom collections:

```php
public function collections(): ?array
{
    return [
        'app.offprint.beta.publication',
        'app.offprint.beta.post',
    ];
}
```

## Operation Filtering

Filter by operation type (only applies to commit events).

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

**Cleanup on deletions:**
```php
public function operations(): ?array
{
    return [SignalCommitOperation::Delete];
}
```

### Checking Operations in Handler

You can also check operation type in your handler:

```php
public function handle(SignalEvent $event): void
{
    $operation = $event->getOperation();

    // Using enum
    if ($operation === SignalCommitOperation::Create) {
        $this->createRecord($event);
    }

    // Using commit helper
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

Filter events by specific users (DIDs).

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
// Track posts from specific content creators
public function collections(): ?array
{
    return ['app.bsky.feed.post'];
}

public function dids(): ?array
{
    return [
        'did:plc:z72i7hdynmk6r22z27h6tvur', // Creator 1
        'did:plc:ragtjsm2j2vknwkz3zp4oxrd', // Creator 2
    ];
}
```

**Dynamic DID filtering:**
```php
use App\Models\MonitoredAccount;

public function dids(): ?array
{
    return MonitoredAccount::pluck('did')->toArray();
}
```

## Custom Filtering

Implement complex filtering logic with `shouldHandle()`.

### Basic Custom Filter

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

### Advanced Examples

**Filter by text content:**
```php
public function shouldHandle(SignalEvent $event): bool
{
    $record = $event->getRecord();

    if (!isset($record->text)) {
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

    // Only handle English posts
    return ($record->langs[0] ?? null) === 'en';
}
```

**Filter by engagement:**
```php
use App\Services\EngagementCalculator;

public function shouldHandle(SignalEvent $event): bool
{
    $engagement = EngagementCalculator::calculate($event);

    // Only handle high-engagement content
    return $engagement > 100;
}
```

**Time-based filtering:**
```php
public function shouldHandle(SignalEvent $event): bool
{
    $timestamp = $event->getTimestamp();

    // Only handle events from the last hour
    return $timestamp->isAfter(now()->subHour());
}
```

## Combining Filters

Stack multiple filter layers for precise targeting:

```php
class HighEngagementPostsSignal extends Signal
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

    // Layer 4: Custom logic
    public function shouldHandle(SignalEvent $event): bool
    {
        $record = $event->getRecord();

        // Must have text
        if (!isset($record->text)) {
            return false;
        }

        // Must be longer than 100 characters
        if (strlen($record->text) < 100) {
            return false;
        }

        // Must have media
        if (!isset($record->embed)) {
            return false;
        }

        return true;
    }

    public function handle(SignalEvent $event): void
    {
        // Only high-quality posts make it here
    }
}
```

## Performance Considerations

### Server-Side vs Client-Side Filtering

**Jetstream Mode (Server-Side):**
- Collections filter applied on server (efficient)
- Only receives matching events
- Lower bandwidth usage

```php
// These collections are sent to Jetstream server
public function collections(): ?array
{
    return ['app.bsky.feed.post', 'app.bsky.feed.like'];
}
```

**Firehose Mode (Client-Side):**
- All filtering happens in your application
- Receives all events (higher bandwidth)
- More control but higher cost

[Learn more about modes →](modes.md)

### Filter Early

Apply the most restrictive filters first:

```php
// Good - filters early
public function eventTypes(): array
{
    return ['commit']; // Narrows to commits only
}

public function collections(): ?array
{
    return ['app.bsky.feed.post']; // Further narrows to posts
}

// Less ideal - too broad
public function eventTypes(): array
{
    return ['commit', 'identity', 'account']; // Too many events
}

public function shouldHandle(SignalEvent $event): bool
{
    // Filtering everything in custom logic (expensive)
    return $event->isCommit() && $event->getCollection() === 'app.bsky.feed.post';
}
```

### Avoid Heavy Logic in shouldHandle()

Keep custom filtering lightweight:

```php
// Good - lightweight checks
public function shouldHandle(SignalEvent $event): bool
{
    $record = $event->getRecord();
    return isset($record->text) && strlen($record->text) > 10;
}

// Less ideal - heavy database queries
public function shouldHandle(SignalEvent $event): bool
{
    // Database query on every event (slow!)
    return User::where('did', $event->did)->exists();
}
```

If you need heavy logic, use queues:

```php
public function shouldQueue(): bool
{
    return true; // Move heavy work to queue
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

### Log What's Being Filtered

```php
public function shouldHandle(SignalEvent $event): bool
{
    $shouldHandle = $this->myCustomLogic($event);

    if (!$shouldHandle) {
        Log::debug('Event filtered out', [
            'signal' => static::class,
            'did' => $event->did,
            'collection' => $event->getCollection(),
            'reason' => 'Failed custom logic',
        ]);
    }

    return $shouldHandle;
}
```

### Test Your Filters

```bash
php artisan signal:test YourSignal
```

This runs your Signal with sample data to verify filtering works correctly.

[Learn more about testing →](testing.md)

## Next Steps

- **[Understand Jetstream vs Firehose →](modes.md)** - Choose the right mode for your filters
- **[Learn about queue integration →](queues.md)** - Handle high-volume filtered events
- **[See real-world examples →](examples.md)** - Learn from production filter patterns
