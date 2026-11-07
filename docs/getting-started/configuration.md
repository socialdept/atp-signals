# Configuration

Part of the [atp-signals docs](../introduction.md#documentation). Every option in `config/atp-signals.php` and the environment variables behind them.

## Configuration File

The configuration lives in `config/atp-signals.php` and is read under the `atp-signals.*` key. `php artisan signal:install` publishes it for you. To publish it on its own:

```bash
php artisan vendor:publish --tag=atp-signals-config
```

The package merges its defaults, so you only need to publish the file to change options that have no environment variable.

## Environment Variables

| Variable | Config key | Default |
|---|---|---|
| `SIGNAL_MODE` | `mode` | `jetstream` |
| `SIGNAL_JETSTREAM_URL` | `websocket_url` | `wss://jetstream2.us-east.bsky.network` |
| `SIGNAL_JETSTREAM_VERSION` | `jetstream_version` | `1` |
| `SIGNAL_JETSTREAM_V2_URL` | `websocket_url_v2` | `wss://jetstream.us-west.bsky.network` |
| `SIGNAL_FIREHOSE_HOST` | `firehose.host` | `bsky.network` |
| `OBELISK_ENABLED` | `obelisk.enabled` | `false` |
| `OBELISK_URL` | `obelisk.base_url` | `http://localhost:6060` |
| `OBELISK_TOKEN` | `obelisk.token` | none |
| `OBELISK_TIMEOUT` | `obelisk.timeout` | `30` |
| `OBELISK_WEBHOOK_PATH` | `obelisk.webhook_path` | `/_atp/obelisk/webhook` |
| `OBELISK_WEBHOOK_SECRET` | `obelisk.webhook_secret` | none |
| `OBELISK_VERIFY_SIGNATURE` | `obelisk.verify_signature` | `true` |
| `OBELISK_QUEUE_EVENTS` | `obelisk.queue_events` | `true` |
| `OBELISK_QUEUE_CONNECTION` | `obelisk.queue_connection` | default connection |
| `OBELISK_QUEUE` | `obelisk.queue_name` | `obelisk` |
| `OBELISK_MAX_QUEUE_DEPTH` | `obelisk.max_queue_depth` | `100` |
| `OBELISK_PULL_LIMIT` | `obelisk.pull.limit` | `200` |
| `OBELISK_PULL_POLL_INTERVAL` | `obelisk.pull.poll_interval` | `5` |
| `OBELISK_PULL_COLLECTION` | `obelisk.pull.collection` | none |
| `SIGNAL_CURSOR_STORAGE` | `cursor_storage` | `database` |
| `SIGNAL_QUEUE_CONNECTION` | `queue.connection` | default connection |
| `SIGNAL_QUEUE` | `queue.queue` | `signal` |
| `SIGNAL_IDLE_TIMEOUT` | `connection.idle_timeout` | `60` |
| `SIGNAL_DEBUG` | `debug` | `false` |

## Mode

```php
'mode' => env('SIGNAL_MODE', 'jetstream'),
```

Where `signal:consume` reads events from:

- `jetstream`: JSON events, with collections filtered on the server (default)
- `firehose`: the raw CBOR/CAR repo stream, filtered on the client
- `obelisk`: polls an Obelisk archive's event log. Webhook delivery from Obelisk works whatever the mode is

`signal:consume --mode=` overrides this for one run. Any other value makes the command exit with `Invalid mode`.

[Learn more about modes](../sources/modes.md)

## Jetstream

```php
'websocket_url' => env('SIGNAL_JETSTREAM_URL', 'wss://jetstream2.us-east.bsky.network'),

'jetstream_version' => (int) env('SIGNAL_JETSTREAM_VERSION', 1),

'websocket_url_v2' => env('SIGNAL_JETSTREAM_V2_URL', 'wss://jetstream.us-west.bsky.network'),
```

- `websocket_url`: the Jetstream v1 endpoint. The consumer appends `/subscribe` and one `wantedCollections` parameter per collection your Signals ask for. The config file lists `wss://jetstream2.us-east.bsky.network` (US East) and `wss://jetstream1.us-west.bsky.network` (US West)
- `jetstream_version`: `1` speaks the legacy wire (`/subscribe`, `time_us` cursors). `2` speaks `subscribeEvents` with `collections` and `kinds` filters, `seq` cursors, `sync` events, and surfaces `OutdatedCursor` advisories as the `SocialDept\AtpSignals\Events\CursorOutdated` Laravel event
- `websocket_url_v2`: the endpoint used when `jetstream_version` is `2`. The consumer appends `/xrpc/network.bsky.jetstream.subscribeEvents`

A v2 cursor is stored under its own `jetstream-v2` key. A fresh v2 consumer with no stored position starts from the v1 cursor when one exists.

If any registered Signal returns `null` from `collections()`, no collection filter is sent and the server delivers every collection.

## Firehose

```php
'firehose' => [
    'host' => env('SIGNAL_FIREHOSE_HOST', 'bsky.network'),
],
```

The consumer connects to:

```
wss://{host}/xrpc/com.atproto.sync.subscribeRepos
```

The firehose has no server-side filtering, so every event arrives and Signals filter on the client. Point `host` at another relay or a PDS to read its stream instead.

## Obelisk

Obelisk is a self-hostable AT Protocol record archive. Signal consumes its event log by webhook (push) or by polling (pull), and the two can run together.

```php
'obelisk' => [
    'enabled' => env('OBELISK_ENABLED', false),
    'base_url' => env('OBELISK_URL', 'http://localhost:6060'),
    'token' => env('OBELISK_TOKEN'),
    'timeout' => (int) env('OBELISK_TIMEOUT', 30),
    'webhook_path' => env('OBELISK_WEBHOOK_PATH', '/_atp/obelisk/webhook'),
    'webhook_secret' => env('OBELISK_WEBHOOK_SECRET'),
    'webhook_middleware' => ['api'],
    'verify_signature' => env('OBELISK_VERIFY_SIGNATURE', true),
    'queue_events' => env('OBELISK_QUEUE_EVENTS', true),
    'queue_connection' => env('OBELISK_QUEUE_CONNECTION'),
    'queue_name' => env('OBELISK_QUEUE', 'obelisk'),
    'max_queue_depth' => (int) env('OBELISK_MAX_QUEUE_DEPTH', 100),
    'pull' => [
        'limit' => (int) env('OBELISK_PULL_LIMIT', 200),
        'poll_interval' => (int) env('OBELISK_PULL_POLL_INTERVAL', 5),
        'collection' => env('OBELISK_PULL_COLLECTION'),
    ],
],
```

- `enabled`: registers the webhook route (default: `false`). Pull mode does not need it
- `base_url`: archive base URL
- `token`: bearer token minted by the archive's `create-token` script
- `timeout`: HTTP timeout in seconds for archive calls (default: `30`)
- `webhook_path`: URL path where Obelisk delivers batches. The route is named `signal.obelisk.webhook`
- `webhook_secret`: HMAC signing secret, returned once by `createWebhook`
- `webhook_middleware`: middleware for the webhook route (default: `['api']`)
- `verify_signature`: verify the HMAC signature (default: `true`). Turning this off lets anything that can reach the route inject events
- `queue_events`: queue each batch instead of handling it in the request (default: `true`)
- `queue_connection`: queue connection for batch jobs (null uses the default connection)
- `queue_name`: queue name for batch jobs (default: `obelisk`)
- `max_queue_depth`: refuse a delivery with a 503 once this many jobs wait on the batch queue, so the archive backs off and redelivers (default: `100`, `0` disables it). Only applies when `queue_events` is on
- `pull.limit`: events per `getEvents` page (default: `200`)
- `pull.poll_interval`: seconds to wait once the backlog is drained (default: `5`). A full page never waits
- `pull.collection`: optional single-collection filter. Null polls every archived collection and lets each Signal filter

With signature verification on and no `webhook_secret`, deliveries are rejected rather than trusted.

[Learn more about Obelisk](../sources/obelisk.md)

## Cursor Storage

The consumers store their position so a restart resumes where the last run stopped.

```php
'cursor_storage' => env('SIGNAL_CURSOR_STORAGE', 'database'),

'cursor_config' => [
    'database' => [
        'table' => 'signal_cursors',
        'connection' => null, // null = default connection
    ],
    'redis' => [
        'connection' => 'default',
        'key' => 'signal:cursor',
    ],
    'file' => [
        'path' => storage_path('signal/cursor.json'),
    ],
],
```

Drivers:

- `database`: one row per consumer in the `signal_cursors` table, created by the package migration (publish tag `signal-migrations`). `connection` picks a database connection. The migration creates `signal_cursors` by name, so if you change `table`, edit the published migration too
- `redis`: one key per consumer on the given Redis connection
- `file`: a JSON file per consumer. The directory is created if missing

Any value other than `redis` or `file` falls back to `database`.

Each consumer keeps its own position under a key:

| Consumer | Database `key` column | Redis key | File |
|---|---|---|---|
| Jetstream v1 and firehose | `jetstream` | `signal:cursor` | `cursor.json` |
| Jetstream v2 | `jetstream-v2` | `signal:cursor:jetstream-v2` | `cursor-jetstream-v2.json` |
| Obelisk pull | `obelisk` | `signal:cursor:obelisk` | `cursor-obelisk.json` |

> [!NOTE]
> Jetstream v1 and the firehose share the default slot, so switching between those two modes resumes from the other mode's cursor. Use `signal:consume --fresh` once after switching.

## Queue

```php
'queue' => [
    'connection' => env('SIGNAL_QUEUE_CONNECTION'),
    'queue' => env('SIGNAL_QUEUE', 'signal'),
],
```

These are the defaults for Signals whose `shouldQueue()` returns `true`. A null connection uses your default queue connection. A Signal can override both:

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

Remember to run a worker for the queue, for example `php artisan queue:work --queue=signal`.

[Learn more about queue integration](../signals/queues.md)

## Signals and Auto-Discovery

```php
'signals' => [
    // App\Signals\PostCreateSignal::class,
],

'auto_discovery' => [
    'enabled' => true,
    'path' => app_path('Signals'),
    'namespace' => 'App\\Signals',
],
```

- `signals`: Signal classes to register explicitly. Each must extend `SocialDept\AtpSignals\Signals\Signal`
- `auto_discovery.enabled`: scan `path` for Signals (default: `true`)
- `auto_discovery.path`: directory to scan (default: `app/Signals`)
- `auto_discovery.namespace`: namespace of the classes in that directory (default: `App\Signals`)

Discovery builds each class name as `namespace` plus the file name, so only files directly in `path` are found. Register a Signal that lives in a subdirectory in `signals`.

To turn discovery off and list Signals by hand:

```php
'auto_discovery' => [
    'enabled' => false,
],

'signals' => [
    \App\Signals\NewPostSignal::class,
    \App\Signals\NewFollowSignal::class,
],
```

`php artisan signal:list` shows what is registered.

## Connection

```php
'connection' => [
    'reconnect_attempts' => 5,
    'reconnect_delay' => 5,
    'max_reconnect_delay' => 60,
    'timeout' => 60,
    'idle_timeout' => (int) env('SIGNAL_IDLE_TIMEOUT', 60),
],
```

- `reconnect_attempts`: how many times the Jetstream and firehose consumers reconnect after the socket closes before giving up and failing the command. The count resets on a successful connection
- `reconnect_delay`: base delay in seconds. Each attempt doubles it (5, 10, 20, ...)
- `max_reconnect_delay`: cap on that delay in seconds. The Obelisk pull consumer also uses `reconnect_delay` and `max_reconnect_delay` to back off when the archive is unreachable
- `idle_timeout`: Jetstream only. Reconnect when no message arrives for this many seconds, which catches dead connections the socket never reports closed. `0` disables it
- `timeout`: present in the config but not read by the package

## Debug

```php
'debug' => env('SIGNAL_DEBUG', false),
```

When on, every dispatch to a Signal is logged at debug level with the Signal name, event kind, collection, operation, DID and whether it was queued.

## Unused Keys

The `performance` (`batch_size`, `rate_limit`, from `SIGNAL_BATCH_SIZE` and `SIGNAL_RATE_LIMIT`) and `logging` (`channel`, `level`, from `SIGNAL_LOG_CHANNEL` and `SIGNAL_LOG_LEVEL`) sections exist in the config file but are not read by the package. The package logs through Laravel's default log channel.

## Runtime Configuration

The `Signal` facade reads `atp-signals.mode` when it starts, so you can change the mode in code before starting a consumer:

```php
use SocialDept\AtpSignals\Facades\Signal;

config(['atp-signals.mode' => 'firehose']);

Signal::getMode(); // 'firehose'
Signal::start();   // blocks until the consumer stops
```

`Signal::start()` takes an optional cursor with the same meaning as on the command: `null` resumes from the stored cursor, `0` ignores it, any other value starts there. `Signal::stop()` stops the consumer for the current mode.

## Troubleshooting

If changes to `.env` have no effect, your configuration is probably cached:

```bash
php artisan config:clear
```

`signal:consume` is a long-running process, so restart it after changing configuration.

## Next Steps

- **[Learn about testing](../signals/testing.md)**: test your Signals
- **[See examples](../examples.md)**: complete Signals for common tasks
- **[Review queue integration](../signals/queues.md)**: run Signals on queues
