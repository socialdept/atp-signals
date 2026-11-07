# Consumption Modes

Part of the [atp-signals docs](../introduction.md#documentation). The three places events come from, Jetstream, the firehose and Obelisk, and how to choose between them.

## Quick Comparison

| | Jetstream | Firehose | Obelisk |
|---|---|---|---|
| **Event format** | JSON | CBOR, decoded in PHP | JSON (webhook or poll) |
| **Delivery** | WebSocket | WebSocket | HTTP webhooks, or cursor polling |
| **Filtering** | Collections on the server (kinds too on v2), the rest in PHP | All in PHP | Collections on the archive, the rest in PHP |
| **Event types** | commit, identity, account (and sync on v2) | commit, identity, account | commit only |
| **PHP process** | Long-running | Long-running | None for push, one for pull |
| **Replay** | No | No | Yes, by rewinding a cursor |
| **External service** | No | No | Yes (an Obelisk archive) |

The mode is set by `SIGNAL_MODE` (`jetstream` by default), or for one run with `php artisan signal:consume --mode=firehose`. Obelisk push delivery does not depend on the mode, see [Obelisk](obelisk.md).

## Jetstream Mode

Jetstream is a JSON event stream built on top of the AT Protocol firehose. It is the default, and the right choice for most apps.

```env
SIGNAL_MODE=jetstream
SIGNAL_JETSTREAM_URL=wss://jetstream2.us-east.bsky.network
```

The default endpoint is US East (`wss://jetstream2.us-east.bsky.network`). There is also US West (`wss://jetstream1.us-west.bsky.network`).

### Server-side filtering

When the consumer connects, it gathers the collections every registered Signal returns from `collections()` and sends them to Jetstream as `wantedCollections`, wildcards included, so Jetstream only sends matching commits:

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post', 'app.bsky.graph.*'];
}
```

If any registered Signal returns `null` from `collections()`, no collection filter is sent, and every Signal receives the whole stream, filtered in PHP.

Events arrive as JSON, which the consumer turns into a `SignalEvent`:

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
  to trigger a backfill (from the Jetstream archive, for example) instead of
  silently losing the gap.

For code that wants Jetstream v2 in batches and keeps its own cursor, see [Streams](streams.md).

## Firehose Mode

The firehose is the relay's raw event stream, `com.atproto.sync.subscribeRepos`, in CBOR. The package decodes it in PHP with atp-cbor, so Signals receive the same `SignalEvent` as in Jetstream mode.

```env
SIGNAL_MODE=firehose
SIGNAL_FIREHOSE_HOST=bsky.network
```

The WebSocket URL is built as:

```
wss://{host}/xrpc/com.atproto.sync.subscribeRepos
```

### Trade-offs

The firehose has no server-side filtering. The consumer receives every commit on the network and filters in PHP, so it takes more bandwidth and CPU than Jetstream for the same Signals:

```php
public function collections(): ?array
{
    return ['app.bsky.feed.post']; // Matched in PHP, the whole stream still arrives
}
```

Commit events carry the record's CID in `$event->commit->cid`.

## Obelisk Mode

Obelisk is a self-hostable AT Protocol record archive that syncs collections from the network and serves them over an authenticated XRPC API. Signal consumes its event log either way round: Obelisk POSTs batched, HMAC-signed deliveries to your app (push), or your app polls the log from a stored cursor (pull).

### When to Use Obelisk

- You want delivery that survives your app being down.
- You want to replay events after a bug, rather than losing them.
- You want to avoid a long-running PHP process (push), or cannot receive webhooks (pull).

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

Events accumulate durably. A consumer that was down resumes where it stopped, because the cursor never advanced.

**2. Replay**

Rewind a cursor and the same events come back in order:

```bash
php artisan signal:obelisk:rewind 0 --name=my-app --execute
```

**3. Batched delivery**

A full batch is delivered immediately, a partial batch at most once per `max_wait_ms`.

**4. Backfill flag**

Historical events arrive with `live: false`, which the package turns into `$event->backfill`:

```php
if ($event->backfill) {
    // Historical event, so skip notifications
}
```

### Trade-offs

**1. External service required**

Obelisk runs alongside your application.

**2. Commit events only**

The archive's event log has no identity or account stream. Use Jetstream or the firehose if you need those.

**3. Latency**

Push adds an HTTP round trip and up to `max_wait_ms` for a partial batch. Pull adds up to `poll_interval`.

[Learn more about Obelisk](obelisk.md)

## Choosing the Right Mode

```
Do you need replay, durable buffering, or webhook delivery?
├─ Yes → Use Obelisk
└─ No
    │
    Can Jetstream filter what you need by collection?
    ├─ Yes → Use Jetstream
    └─ No → Use Jetstream or Firehose, filtering in PHP
```

## Switching Between Modes

Signals work in every mode without changes. The differences are in where filtering happens and which event types arrive. Obelisk events also carry `$event->backfill` (boolean) and `$event->cursor` (the archive event id, a string). Both are `null` for Jetstream and firehose events.

Switch with the environment, and restart the consumer:

```env
SIGNAL_MODE=firehose
```

Or start a consumer from code, which uses the configured mode:

```php
use SocialDept\AtpSignals\Facades\Signal;

config(['atp-signals.mode' => 'jetstream']);

Signal::start();
```

To check the mode at runtime:

```php
$mode = config('atp-signals.mode'); // 'jetstream', 'firehose', or 'obelisk'
```

Push delivery is independent of the mode. To check whether it is enabled:

```php
$push = config('atp-signals.obelisk.enabled'); // true or false
```

> [!WARNING]
> Jetstream v1 and the firehose keep their cursor under the same key, so do not run both against one cursor store at the same time.

## Next Steps

- **[Queue integration](../signals/queues.md)**: Handle high-volume events on a queue.
- **[Configuration](../getting-started/configuration.md)**: Every option.
- **[Examples](../examples.md)**: Signals for common jobs.
