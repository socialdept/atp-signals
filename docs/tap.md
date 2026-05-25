# Tap Mode

Tap is a **Go binary service** from the AT Protocol team that provides filtered, verified webhook delivery of repo events with automatic backfilling. Instead of maintaining a persistent WebSocket connection from PHP, Tap runs as an external service and POSTs events to your Laravel application's webhook endpoint.

## Why Tap?

Jetstream and Firehose require a long-running PHP process (`signal:consume`) to maintain a WebSocket connection. This works well, but comes with trade-offs:

- PHP processes can be memory-hungry over long periods
- WebSocket reconnection logic lives in your application
- You need to manage cursor persistence yourself

**Tap inverts this model.** It runs as a lightweight Go binary managed by Supervisor or systemd, and delivers events to your app via HTTP webhooks. Your Laravel app just handles incoming requests — no long-running PHP process needed.

### When to Use Tap

Choose Tap when you:

- Want webhook-based event delivery instead of WebSocket consumers
- Need automatic backfilling of missed events
- Prefer managing a Go binary over a long-running PHP process
- Are tracking specific repositories (DIDs) rather than the full firehose
- Want server-side collection filtering with a persistent external service

### When Not to Use Tap

Stick with Jetstream or Firehose if you:

- Don't want to run an external binary
- Need the simplest possible setup
- Are only doing development/prototyping

## How It Works

```
┌─────────────┐     webhook POST     ┌─────────────────┐
│  Tap binary  │ ──────────────────→ │  Laravel app     │
│  (Go service)│                     │  /webhook route  │
│              │                     │                  │
│  • Connects  │                     │  • Authenticates │
│    to AT     │                     │  • Normalizes    │
│    Protocol  │                     │  • Dispatches to │
│  • Filters   │                     │    matching      │
│    by        │                     │    Signals       │
│    collection│                     │                  │
│  • Backfills │                     │                  │
│    missed    │                     │                  │
│    events    │                     │                  │
└─────────────┘                      └─────────────────┘
```

1. **Tap connects** to the AT Protocol relay and subscribes to events
2. **Tap filters** events by configured collection filters (`TAP_COLLECTION_FILTERS`)
3. **Tap POSTs** matching events as JSON to your app's webhook endpoint
4. **Your app** receives the webhook, normalizes the event into a `SignalEvent`, and dispatches it to matching Signals
5. **Backfilling**: When a new repo is added, Tap automatically backfills historical events

## Setup

### 1. Install Tap

Install the Tap binary on your server. See the [AT Protocol Tap documentation](https://github.com/bluesky-social/indigo/tree/main/cmd/tap) for installation instructions.

### 2. Enable Tap in Configuration

```env
TAP_ENABLED=true
TAP_URL=http://localhost:7374
TAP_ADMIN_PASSWORD=your-secret-password
```

### 3. Configure Your Signals

Your existing Signals work with Tap — no changes needed. Tap uses the same `collections()`, `operations()`, and `dids()` filters:

```php
class PublicationSyncSignal extends Signal
{
    public function eventTypes(): array
    {
        return [SignalEventType::Commit];
    }

    public function collections(): ?array
    {
        return ['site.standard.publication', 'site.standard.document'];
    }

    public function handle(SignalEvent $event): void
    {
        // Works the same as Jetstream/Firehose
    }
}
```

### 4. Generate the Tap Env File

```bash
php artisan signal:tap:restart --write-only
```

This scans your registered Signals, collects their collection filters, and writes a Tap env file. More on this in [Managing Tap](#managing-tap).

### 5. Start Tap

Run Tap with the generated env file:

```bash
set -a && source storage/tap/env && set +a && tap run
```

Or use Supervisor (recommended for production):

```ini
[program:tap]
command=bash -c 'set -a && source /path/to/storage/tap/env && set +a && exec tap run'
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/logs/tap.log
```

## Configuration Reference

All Tap configuration lives in the `tap` key of `config/atp-signals.php`:

```php
'tap' => [
    'enabled' => env('TAP_ENABLED', false),
    'base_url' => env('TAP_URL', 'http://localhost:7374'),
    'admin_password' => env('TAP_ADMIN_PASSWORD'),
    'webhook_path' => env('TAP_WEBHOOK_PATH', '/_atp/tap/webhook'),
    'webhook_middleware' => ['api'],
    'queue_events' => env('TAP_QUEUE_EVENTS', true),
    'queue_connection' => env('TAP_QUEUE_CONNECTION'),
    'queue_name' => env('TAP_QUEUE', 'tap'),
    'env_path' => env('TAP_ENV_PATH', storage_path('tap/env')),
    'restart_command' => env('TAP_RESTART_COMMAND'),
],
```

### Options

| Option | Default | Description |
|---|---|---|
| `enabled` | `false` | Enable Tap webhook route registration |
| `base_url` | `http://localhost:7374` | Tap service URL for API calls |
| `admin_password` | `null` | Password for Tap API and webhook authentication |
| `webhook_path` | `/_atp/tap/webhook` | URL path where Tap sends events |
| `webhook_middleware` | `['api']` | Middleware applied to the webhook route |
| `queue_events` | `true` | Queue webhook events instead of processing synchronously |
| `queue_connection` | `null` | Queue connection for Tap events (null = default) |
| `queue_name` | `tap` | Queue name for Tap events |
| `env_path` | `storage/tap/env` | Path where the Tap env file is written |
| `restart_command` | `null` | Shell command to restart Tap (e.g. `supervisorctl restart tap`) |

### Environment Variables

```env
# Enable Tap mode
TAP_ENABLED=true

# Tap service URL
TAP_URL=http://localhost:7374

# Shared authentication password
TAP_ADMIN_PASSWORD=your-secret-password

# Webhook endpoint path
TAP_WEBHOOK_PATH=/_atp/tap/webhook

# Queue configuration
TAP_QUEUE_EVENTS=true
TAP_QUEUE_CONNECTION=redis
TAP_QUEUE=tap

# Env file and restart management
TAP_ENV_PATH=/path/to/storage/tap/env
TAP_RESTART_COMMAND="supervisorctl restart tap"
```

## Managing Tap

### Restart Command

The `signal:tap:restart` command is the primary tool for managing Tap. It:

1. Discovers all registered Signals
2. Collects their collection filters
3. Writes a Tap env file with the correct `TAP_COLLECTION_FILTERS`
4. Optionally restarts the Tap service

```bash
# Write env file and restart Tap
php artisan signal:tap:restart

# Write env file only (don't restart)
php artisan signal:tap:restart --write-only
```

The generated env file looks like:

```
TAP_WEBHOOK_URL='https://yourapp.test/_atp/tap/webhook'
TAP_ADMIN_PASSWORD='your-secret-password'
TAP_COLLECTION_FILTERS='app.bsky.actor.profile,site.standard.document,site.standard.publication'
```

**When to run this command:**
- After adding a new Signal with new collection filters
- After removing a Signal
- After changing the webhook URL or admin password
- As part of your deploy script

#### Deploy Script Example

```bash
php artisan migrate --force
php artisan signal:tap:restart
php artisan queue:restart
```

#### Restart Command Configuration

Configure a restart command to automatically restart Tap after writing the env file:

```env
TAP_RESTART_COMMAND="supervisorctl restart tap"
```

Without a restart command configured, the command will write the env file and remind you to restart Tap manually.

### Adding & Removing Repos

Tap tracks specific repositories (users) by DID. Use artisan commands to manage tracked repos:

```bash
# Add a repo to Tap tracking
php artisan signal:tap:add did:plc:z72i7hdynmk6r22z27h6tvur

# Remove a repo from Tap tracking
php artisan signal:tap:remove did:plc:z72i7hdynmk6r22z27h6tvur
```

When you add a repo, Tap automatically backfills historical events for that user.

### Health Check

Check the status of the Tap service:

```bash
php artisan signal:tap:status
```

This calls Tap's health endpoint and displays the response in a table.

## Webhook Authentication

Tap and your Laravel app share a secret password (`admin_password`) for authentication. When configured:

- **Outgoing requests** to Tap's API (add/remove repos, health) use HTTP Basic Auth with `admin:{password}`
- **Incoming webhooks** from Tap are verified against the same password via the `Authorization: Basic` header

If no `admin_password` is set, authentication is disabled (not recommended for production).

## Event Processing

### Queue vs Synchronous

By default, Tap events are queued (`queue_events = true`). This is recommended because:

- Webhook responses need to be fast (Tap may time out waiting)
- Signal handlers can do expensive work without blocking
- Failed jobs can be retried

To process events synchronously (useful for debugging):

```env
TAP_QUEUE_EVENTS=false
```

### Backfill Detection

Tap events include a `live` field indicating whether the event is a live event or a backfill. Signal normalizes this into the `backfill` property on `SignalEvent`:

```php
public function handle(SignalEvent $event): void
{
    if ($event->backfill) {
        // This is a historical event from backfilling
        // You might want to skip notifications, etc.
        $this->syncWithoutNotification($event);
        return;
    }

    // This is a live event
    $this->syncAndNotify($event);
}
```

### Event Types

Tap delivers two types of events:

**Record events** (`type: "record"`) — Normalized to `commit` kind:

```json
{
    "id": 12345,
    "type": "record",
    "record": {
        "live": true,
        "rev": "3kb3fge5lm32x",
        "did": "did:plc:abc123",
        "collection": "app.bsky.feed.post",
        "rkey": "3kb3fge5lm32x",
        "action": "create",
        "cid": "bafyreig...",
        "record": { "$type": "app.bsky.feed.post", "text": "Hello" }
    }
}
```

**Identity events** (`type: "identity"`) — Normalized to `identity` kind:

```json
{
    "id": 12346,
    "type": "identity",
    "identity": {
        "did": "did:plc:abc123",
        "handle": "alice.bsky.social",
        "isActive": true,
        "status": "active"
    }
}
```

Both are normalized into `SignalEvent` objects, so your Signal handlers work identically regardless of whether events come from Jetstream, Firehose, or Tap.

## Wildcard Collections

Signals can use wildcard patterns in their `collections()` method:

```php
public function collections(): ?array
{
    return ['app.bsky.feed.*', 'site.standard.*'];
}
```

When `signal:tap:restart` builds the env file, wildcard patterns are passed through as-is to `TAP_COLLECTION_FILTERS`. Whether Tap expands wildcards or requires exact collection names depends on your Tap version.

## Signals with Null Collections

If a Signal returns `null` from `collections()` (meaning it listens to all collections), the restart command will skip that signal and display a warning:

```
⚠ Signal App\Signals\CatchAllSignal listens to all collections (null) — skipping.
```

Only Signals with explicit collection filters contribute to `TAP_COLLECTION_FILTERS`.

## TapClient

For programmatic access, resolve `TapClient` from the container:

```php
use SocialDept\AtpSignals\Tap\TapClient;

$client = app(TapClient::class);

// Add repos
$client->addRepo('did:plc:abc123');
$client->addRepo(['did:plc:abc123', 'did:plc:def456']);

// Remove repos
$client->removeRepo('did:plc:abc123');

// Health check
$health = $client->health();
```

## Tap Commands Reference

| Command | Description |
|---|---|
| `signal:tap:restart` | Write Tap env file and restart the service |
| `signal:tap:restart --write-only` | Write Tap env file without restarting |
| `signal:tap:add {did}` | Add a repo to Tap tracking |
| `signal:tap:remove {did}` | Remove a repo from Tap tracking |
| `signal:tap:status` | Check Tap service health |

## High-Volume Considerations

> **Warning:** Tap delivers events via HTTP webhooks, which means every event is an individual HTTP request to your application. This has significant performance implications at scale.

### The Problem

When tracking repositories with high activity, or when backfilling historical events, Tap can deliver **thousands of webhook requests in a short period**. Each request:

- Opens a new HTTP connection to your application
- Goes through the full Laravel HTTP kernel (middleware, routing, etc.)
- Requires authentication and event normalization
- Dispatches to the queue or processes synchronously

This can quickly overwhelm your application, exhaust database connections, fill up your queue, or trigger rate limiting from your web server.

### Mitigation Strategies

**1. Always Queue Events (Default)**

Never process Tap events synchronously in production. The default `queue_events = true` is critical:

```env
TAP_QUEUE_EVENTS=true
TAP_QUEUE_CONNECTION=redis
TAP_QUEUE=tap
```

This ensures the webhook responds immediately and defers processing to queue workers.

**2. Use a Dedicated Queue**

Keep Tap events on a separate queue so they don't block your application's regular jobs:

```env
TAP_QUEUE=tap
```

Run a dedicated worker for Tap events:

```bash
php artisan queue:work redis --queue=tap --max-jobs=1000 --max-time=300
```

**3. Rate Limit Your Web Server**

Configure Nginx or Apache to rate limit the webhook endpoint to prevent Tap from overwhelming your application during backfills:

```nginx
# Nginx rate limiting example
limit_req_zone $binary_remote_addr zone=tap:10m rate=100r/s;

location /_atp/tap/webhook {
    limit_req zone=tap burst=200 nodelay;
    # ...
}
```

**4. Be Mindful of Backfills**

When you add a new repo with `signal:tap:add`, Tap backfills **all** historical events for that repository. For active users, this can mean thousands of events delivered in rapid succession.

Consider handling backfilled events differently in your Signals:

```php
public function handle(SignalEvent $event): void
{
    if ($event->backfill) {
        // Lightweight processing for historical data
        $this->syncRecord($event);
        return;
    }

    // Full processing for live events
    $this->syncRecord($event);
    $this->sendNotification($event);
    $this->updateAnalytics($event);
}
```

**5. Monitor Queue Depth**

Watch your queue size during backfills. If the Tap queue grows faster than workers can process:

```bash
# Check queue size (Redis)
redis-cli llen queues:tap
```

Consider scaling up workers temporarily:

```ini
# Supervisor: increase numprocs during backfills
[program:tap-worker]
command=php artisan queue:work redis --queue=tap --max-jobs=1000
numprocs=4
```

**6. Batch Database Operations**

If your Signal handlers write to the database, batch operations to reduce connection overhead:

```php
public function handle(SignalEvent $event): void
{
    // Instead of individual inserts, consider using
    // upserts or bulk operations where possible
    MyModel::upsert([
        'did' => $event->did,
        'rkey' => $event->commit->rkey,
        'data' => json_encode($event->getRecord()),
    ], ['did', 'rkey'], ['data']);
}
```

### When Tap is Not the Right Choice

If you're consuming the full network firehose (tens of thousands of events per second), Tap's per-event HTTP overhead becomes prohibitive. Use Jetstream or Firehose with `signal:consume` instead — they stream events over a single WebSocket connection with far less overhead per event.

Tap works best when tracking a **bounded set of repositories or collections**, not the entire network.

## Debugging

Enable debug mode to see detailed Tap webhook logs:

```env
SIGNAL_DEBUG=true
```

This logs every incoming webhook with event details:

```
[Signal] Tap webhook received {"tap_id": 12345, "type": "record", "did": "did:plc:abc123", ...}
```

## Next Steps

- **[Creating Signals](signals.md)** — Build Signals that work with Tap
- **[Filtering Events](filtering.md)** — Target specific collections and operations
- **[Queue Integration](queues.md)** — Configure queues for Tap event processing
- **[Configuration](configuration.md)** — Full configuration reference
