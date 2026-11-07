# Consumption Modes

Signal supports three modes for consuming AT Protocol events. Understanding the differences is crucial for building efficient, scalable applications.

## Quick Comparison

| Feature              | Jetstream         | Firehose               | Obelisk                          |
|----------------------|-------------------|------------------------|----------------------------------|
| **Event Format**     | Simplified JSON   | Raw CBOR/CAR           | JSON (webhook or poll)           |
| **Delivery**         | WebSocket         | WebSocket              | HTTP webhooks, or cursor polling |
| **Filtering**        | Server-side       | Client-side            | Server-side (collection, action, record fields, audience) |
| **PHP Process**      | Long-running      | Long-running           | None for push, one for pull      |
| **Replay**           | No                | No                     | Yes — rewind a cursor            |
| **Backfilling**      | No                | No                     | Yes (archive-side)               |
| **External Service** | No                | No                     | Yes (Obelisk + Postgres)         |
| **Best For**         | Most applications | Comprehensive indexing | Durable delivery you can replay  |

## Jetstream Mode

Jetstream is a **simplified, JSON-based event stream** built on top of the AT Protocol Firehose.
    
### When to Use Jetstream

Choose Jetstream if you're:

- Building production applications where efficiency matters
- Concerned about bandwidth and server costs
- Processing high volumes of events
- Want server-side filtering for reduced bandwidth

### Configuration

Set Jetstream as your mode in `.env`:

```env
SIGNAL_MODE=jetstream
SIGNAL_JETSTREAM_URL=wss://jetstream2.us-east.bsky.network
```

### Jetstream v2

The v2 wire (`SIGNAL_JETSTREAM_VERSION=2`) speaks
`/xrpc/network.bsky.jetstream.subscribeEvents` against the v2 hosts:

```env
SIGNAL_JETSTREAM_VERSION=2
SIGNAL_JETSTREAM_V2_URL=wss://jetstream.us-west.bsky.network
```

What changes compared to v1:

- **Seq cursors.** v2 identifies events by a monotonic `seq` instead of a
  `time_us` timestamp. The cursor is stored under its own `jetstream-v2` key,
  so the two versions never read each other's positions. A fresh v2 consumer
  seeds itself from an existing v1 cursor automatically (the server reads
  cursor values >= 1e15 as unix-microsecond timestamps, which is exactly what
  a v1 cursor is), so migrating loses nothing.
- **Sync events.** A fourth event kind marking a repo divergence: the
  account's records should be re-fetched from its PDS rather than folded from
  the stream. Handle it with `SignalEventType::Sync` in `eventTypes()` and
  read it from `$event->sync`. The v1 wire never emits this kind.
- **Kinds filter.** The consumer tells the server which event kinds your
  signals handle, so unhandled kinds never leave the server.
- **Gap advisories.** When a stored cursor falls outside the server's lookback
  window, the server clamps the stream and the package fires the
  `SocialDept\AtpSignals\Events\CursorOutdated` Laravel event. Listen for it
  to trigger a backfill (e.g. from the Jetstream archive) instead of silently
  losing the gap.

### Available Endpoints

Jetstream has multiple regional endpoints:

**US East (Default):**
```env
SIGNAL_JETSTREAM_URL=wss://jetstream2.us-east.bsky.network
```

**US West:**
```env
SIGNAL_JETSTREAM_URL=wss://jetstream1.us-west.bsky.network
```

Choose the endpoint closest to your server for best performance.

### Advantages

**1. Simplified JSON Format**

Events arrive as clean JSON objects:

```json
{
  "did": "did:plc:z72i7hdynmk6r22z27h6tvur",
  "time_us": 1234567890,
  "kind": "commit",
  "commit": {
    "rev": "abc123",
    "operation": "create",
    "collection": "app.bsky.feed.post",
    "rkey": "3k2yihcrr2c2a",
    "record": {
      "text": "Hello World!",
      "createdAt": "2024-01-15T10:30:00Z"
    }
  }
}
```

No complex parsing or decoding required.

**2. Server-Side Filtering**

Your collection filters are sent to Jetstream:

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post', 'app.bsky.feed.like'];
}
```

Jetstream only sends matching events, dramatically reducing bandwidth.

**3. Lower Bandwidth**

Only receive the events you care about:

- **Jetstream**: Receive ~1,000 events/sec for specific collections
- **Firehose**: Receive ~50,000 events/sec for everything

**4. Lower Processing Overhead**

JSON parsing is faster than CBOR/CAR decoding:

- **Jetstream**: Simple JSON deserialization
- **Firehose**: Native CBOR/CAR decoding (no external dependencies)

### Limitations

**1. Client-Side Wildcards**

Wildcard patterns work client-side only:

```php
public function collections(): ?array
{
    return ['app.bsky.feed.*']; // Still receives all collections
}
```

The wildcard matching happens in your app, not on the server.

### Example Configuration

```php
// config/signal.php
return [
    'mode' => env('SIGNAL_MODE', 'jetstream'),

    'jetstream' => [
        'websocket_url' => env(
            'SIGNAL_JETSTREAM_URL',
            'wss://jetstream2.us-east.bsky.network'
        ),
    ],
];
```

## Firehose Mode

Firehose is the **raw AT Protocol event stream** with comprehensive support for all collections.

### When to Use Firehose

Choose Firehose if you're:

- Building comprehensive indexing systems
- Developing AT Protocol infrastructure
- Need access to raw CBOR/CAR data
- Prefer client-side filtering control

### Configuration

Set Firehose as your mode in `.env`:

```env
SIGNAL_MODE=firehose
SIGNAL_FIREHOSE_HOST=bsky.network
```

### Advantages

**1. Raw Event Access**

Full access to raw AT Protocol data:

```php
public function handle(SignalEvent $event): void
{
    // Access raw CBOR/CAR data
    $cid = $event->commit->cid;
    $blocks = $event->commit->blocks;
}
```

**2. Comprehensive Events**

Every event from the AT Protocol network arrives:

- All collections (standard and custom)
- All operations (create, update, delete)
- All metadata and context
- Complete repository commits

**3. Complete Control**

Full access to raw AT Protocol data:

- CID (Content Identifiers)
- Block structures
- CAR file data
- Complete repository commits

### Trade-offs

**1. Client-Side Filtering**

All filtering happens in your application:

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post']; // Still receives all events
}
```

Your app receives everything and filters locally.

**2. Higher Bandwidth**

Receive the full event stream:

- **~50,000+ events per second** during peak times
- **~10-50 MB/s** of data throughput
- Requires adequate network capacity

**3. More Processing Overhead**

Native CBOR/CAR decoding:

```php
// Signal handles decoding natively with no external dependencies
$record = $event->getRecord(); // Decoded from CBOR/CAR
```

Processing is more CPU-intensive than Jetstream's JSON, but requires no additional packages.

### Example Configuration

```php
// config/signal.php
return [
    'mode' => env('SIGNAL_MODE', 'jetstream'),

    'firehose' => [
        'host' => env('SIGNAL_FIREHOSE_HOST', 'bsky.network'),
    ],
];
```

The WebSocket URL is constructed as:
```
wss://{host}/xrpc/com.atproto.sync.subscribeRepos
```

## Obelisk Mode

Obelisk is a **self-hostable AT Protocol record archive** that syncs collections from the network and serves them over an authenticated XRPC API. Signal consumes its event log either way round: Obelisk POSTs batched, HMAC-signed deliveries to your app (push), or your app polls the log from a stored cursor (pull).

### When to Use Obelisk

Choose Obelisk if you're:

- Running in production and want delivery that survives your app being down
- Wanting to replay events after a bug, rather than losing them
- Filtering on more than a collection name (record field matchers, audiences, link-based feeds)
- Avoiding a long-running PHP process (push), or unable to receive webhooks (pull)

### Configuration

```env
OBELISK_ENABLED=true
OBELISK_URL=http://localhost:6060
OBELISK_TOKEN=your-archive-bearer-token
OBELISK_WEBHOOK_SECRET=from-createWebhook

# Pull mode only
SIGNAL_MODE=obelisk
```

### Advantages

**1. The archive is the buffer**

Events accumulate durably. A consumer down for a week resumes exactly where it stopped; the cursor never advanced.

**2. Replay**

Rewind a cursor and the same events come back in order:

```bash
php artisan signal:obelisk:rewind 0 --name=my-app --execute
```

**3. Batched delivery, never a flood**

A full batch is delivered immediately, a partial batch at most once per `max_wait_ms`. Backfills arrive at a rate your app can absorb.

**4. Backfill flag**

Historical events arrive with `live: false`:

```php
if ($event->backfill) {
    // Historical event — skip notifications
}
```

### Trade-offs

**1. External Service Required**

Obelisk is a Bun process plus Postgres (and Tab for network sync) running alongside your application.

**2. Commit events only**

The archive's event log has no identity or account stream. Use Jetstream or Firehose if you need those.

**3. Latency**

Push adds an HTTP round-trip and up to `max_wait_ms` for a partial batch. Pull adds up to `poll_interval`.

[Learn more about Obelisk →](obelisk.md)

## Choosing the Right Mode

### Decision Tree

```
Do you need raw CBOR/CAR access?
├─ Yes → Use Firehose
└─ No
    │
    Do you need replay, durable buffering, or webhook delivery?
    ├─ Yes → Use Obelisk
    └─ No
        │
        Do you want server-side filtering?
        ├─ Yes → Use Jetstream (recommended)
        └─ No → Use Firehose
```

### Use Case Examples

**Social Media Analytics (Jetstream)**

```php
// Efficient monitoring with server-side filtering
public function collections(): ?array
{
    return [
        'app.bsky.feed.post',
        'app.bsky.feed.like',
        'app.bsky.graph.follow',
    ];
}
```

**Content Moderation (Jetstream)**

```php
// Standard content monitoring
public function collections(): ?array
{
    return ['app.bsky.feed.*'];
}
```

**Comprehensive Indexer (Firehose)**

```php
// Index everything with raw data access
public function collections(): ?array
{
    return null; // All collections
}
```

**AppView Sync (Obelisk)**

```php
// Sync custom collections with automatic backfilling
public function collections(): ?array
{
    return [
        'site.standard.publication',
        'site.standard.document',
    ];
}

public function handle(SignalEvent $event): void
{
    if ($event->backfill) {
        $this->syncQuietly($event);
        return;
    }

    $this->syncAndNotify($event);
}
```

## Switching Between Modes

You can switch modes without code changes:

### Option 1: Environment Variable

```env
# Development - comprehensive testing
SIGNAL_MODE=firehose

# Production - efficient processing
SIGNAL_MODE=jetstream
```

### Option 2: Runtime Configuration

```php
use SocialDept\AtpSignals\Facades\Signal;

// Set mode dynamically
config(['signal.mode' => 'jetstream']);

Signal::start();
```

## Performance Comparison

### Bandwidth Usage

**Processing 1 hour of posts:**

| Mode      | Data Received     | Bandwidth |
|-----------|-------------------|-----------|
| Jetstream | ~50,000 events    | ~10 MB    |
| Firehose  | ~5,000,000 events | ~500 MB   |

**Savings:** 50x reduction with Jetstream

### CPU Usage

**Processing same events:**

| Mode      | CPU Usage | Processing Time |
|-----------|-----------|-----------------|
| Jetstream | ~5%       | 0.1ms per event |
| Firehose  | ~20%      | 0.4ms per event |

**Savings:** 4x more efficient with Jetstream

### Cost Implications

For a medium-traffic application:

| Mode      | Monthly Bandwidth | Est. Cost* |
|-----------|-------------------|------------|
| Jetstream | ~20 GB            | ~$2        |
| Firehose  | ~10 TB            | ~$1000     |

*Estimates vary by provider and usage

## Best Practices

### Start with Jetstream

Start with Jetstream for most applications:

```env
SIGNAL_MODE=jetstream
```

Switch to Firehose only if you need raw CBOR/CAR access.

### Use Firehose for Development

Test with Firehose in development to see all events:

```env
# .env.local
SIGNAL_MODE=firehose

# .env.production
SIGNAL_MODE=jetstream
```

### Monitor Performance

Track your Signal's performance:

```php
public function handle(SignalEvent $event): void
{
    $start = microtime(true);

    // Your logic

    $duration = microtime(true) - $start;

    if ($duration > 0.1) {
        Log::warning('Slow signal processing', [
            'signal' => static::class,
            'duration' => $duration,
            'mode' => config('signal.mode'),
        ]);
    }
}
```

### Use Queues with Firehose

Firehose generates high volume. Use queues to avoid blocking:

```php
public function shouldQueue(): bool
{
    // Queue when using Firehose
    return config('signal.mode') === 'firehose';
}
```

[Learn more about queue integration →](queues.md)

## Testing Both Modes

Test your Signals work in both modes:

```bash
# Test with Jetstream
SIGNAL_MODE=jetstream php artisan signal:test MySignal

# Test with Firehose
SIGNAL_MODE=firehose php artisan signal:test MySignal
```

[Learn more about testing →](testing.md)

## Common Questions

### Can I use both modes simultaneously?

No, each consumer runs in one mode. However, you can run multiple consumers:

```bash
# Terminal 1 - Jetstream consumer
SIGNAL_MODE=jetstream php artisan signal:consume

# Terminal 2 - Firehose consumer
SIGNAL_MODE=firehose php artisan signal:consume
```

### Will my Signals break if I switch modes?

Signals work in all modes without changes. The main difference is:
- Jetstream provides server-side filtering (more efficient)
- Firehose provides raw CBOR/CAR data access (more comprehensive)
- Obelisk provides replayable delivery, push or pull

Obelisk adds two extra properties: `$event->backfill` (boolean) to distinguish live events from historical ones, and `$event->cursor` (string) carrying the archive event id. Both are `null` for Jetstream/Firehose events.

### How do I know which mode I'm using?

Check at runtime:

```php
$mode = config('atp-signals.mode'); // 'jetstream', 'firehose', or 'obelisk'
```

Push delivery is independent of the mode — check whether it is enabled:

```php
$push = config('atp-signals.obelisk.enabled'); // true or false
```

### Can I switch modes while consuming?

No, you must restart the consumer:

```bash
# Stop current consumer (Ctrl+C)

# Change mode
# Edit .env: SIGNAL_MODE=firehose

# Start new consumer
php artisan signal:consume
```

## Next Steps

- **[Learn about queue integration →](queues.md)** - Handle high-volume events efficiently
- **[Review configuration options →](configuration.md)** - Fine-tune your setup
- **[See real-world examples →](examples.md)** - Learn from production patterns
