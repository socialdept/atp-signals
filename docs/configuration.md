# Configuration

Signal's configuration file provides complete control over how your application consumes AT Protocol events.

## Configuration File

After installation, configuration lives in `config/signal.php`.

### Publishing Configuration

Publish the config file manually if needed:

```bash
php artisan vendor:publish --tag=signal-config
```

This creates `config/signal.php` with all available options.

## Environment Variables

Most configuration can be set via `.env` for environment-specific values.

### Basic Configuration

```env
# Consumer Mode (jetstream or firehose)
SIGNAL_MODE=jetstream

# Jetstream Configuration
SIGNAL_JETSTREAM_URL=wss://jetstream2.us-east.bsky.network

# Firehose Configuration
SIGNAL_FIREHOSE_HOST=bsky.network

# Tap Configuration (optional, webhook-based delivery)
TAP_ENABLED=false
TAP_URL=http://localhost:7374
TAP_ADMIN_PASSWORD=

# Cursor Storage (database, redis, or file)
SIGNAL_CURSOR_STORAGE=database

# Queue Configuration
SIGNAL_QUEUE_CONNECTION=redis
SIGNAL_QUEUE=signal
```

## Consumer Mode

Choose between Jetstream and Firehose mode.

### Configuration

```php
'mode' => env('SIGNAL_MODE', 'jetstream'),
```

**Options:**
- `jetstream` - JSON events, server-side filtering (default)
- `firehose` - CBOR/CAR events, client-side filtering

**Environment Variable:**
```env
SIGNAL_MODE=jetstream
```

**When to use each:**
- **Jetstream**: Most applications, production efficiency
- **Firehose**: Raw CBOR/CAR access, comprehensive indexing

[Learn more about modes →](modes.md)

## Jetstream Configuration

Configuration specific to Jetstream mode.

### WebSocket URL

```php
'jetstream' => [
    'websocket_url' => env(
        'SIGNAL_JETSTREAM_URL',
        'wss://jetstream2.us-east.bsky.network'
    ),
],
```

**Available Endpoints:**

**US East (Default):**
```env
SIGNAL_JETSTREAM_URL=wss://jetstream2.us-east.bsky.network
```

**US West:**
```env
SIGNAL_JETSTREAM_URL=wss://jetstream1.us-west.bsky.network
```

Choose the endpoint closest to your server for best latency.

## Firehose Configuration

Configuration specific to Firehose mode.

### Host

```php
'firehose' => [
    'host' => env('SIGNAL_FIREHOSE_HOST', 'bsky.network'),
],
```

The WebSocket URL is constructed as:
```
wss://{host}/xrpc/com.atproto.sync.subscribeRepos
```

**Environment Variable:**
```env
SIGNAL_FIREHOSE_HOST=bsky.network
```

**Default Host:** `bsky.network`

**Custom Hosts:** If you're running your own AT Protocol PDS, specify it here:
```env
SIGNAL_FIREHOSE_HOST=my-pds.example.com
```

## Tap Configuration

Tap is an optional Go binary service that delivers events via HTTP webhooks instead of WebSocket connections.

### Enable Tap

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

**Environment Variables:**
```env
TAP_ENABLED=true
TAP_URL=http://localhost:7374
TAP_ADMIN_PASSWORD=your-secret-password
TAP_WEBHOOK_PATH=/_atp/tap/webhook
TAP_QUEUE_EVENTS=true
TAP_QUEUE_CONNECTION=redis
TAP_QUEUE=tap
TAP_ENV_PATH=/path/to/storage/tap/env
TAP_RESTART_COMMAND="supervisorctl restart tap"
```

**Options:**
- `enabled`: Register the webhook route (default: `false`)
- `base_url`: Tap service API URL for management commands
- `admin_password`: Shared password for API and webhook authentication
- `webhook_path`: URL path where Tap sends events
- `webhook_middleware`: Middleware for the webhook route
- `queue_events`: Queue incoming events vs process synchronously (default: `true`)
- `queue_connection`: Queue connection for Tap jobs (null = default)
- `queue_name`: Queue name for Tap jobs (default: `tap`)
- `env_path`: File path where `signal:tap:restart` writes the env file
- `restart_command`: Shell command to restart Tap after writing the env file

[Learn more about Tap →](tap.md)

## Cursor Storage

Configure how Signal stores cursor positions for resuming after disconnections.

### Storage Driver

```php
'cursor_storage' => env('SIGNAL_CURSOR_STORAGE', 'database'),
```

**Available Drivers:**
- `database` - Store in database (recommended for production)
- `redis` - Store in Redis (high performance)
- `file` - Store in filesystem (development only)

**Environment Variable:**
```env
SIGNAL_CURSOR_STORAGE=database
```

### Database Driver

Uses Laravel's default database connection.

**Configuration:**
```php
'cursor_storage' => 'database',
```

**Requires:**
- Migration published and run
- Database connection configured

**Table:** `signal_cursors`

### Redis Driver

Stores cursors in Redis for high performance.

**Configuration:**
```php
'cursor_storage' => 'redis',

'redis' => [
    'connection' => env('SIGNAL_REDIS_CONNECTION', 'default'),
    'key_prefix' => env('SIGNAL_REDIS_PREFIX', 'signal:cursor:'),
],
```

**Environment Variables:**
```env
SIGNAL_CURSOR_STORAGE=redis
SIGNAL_REDIS_CONNECTION=default
SIGNAL_REDIS_PREFIX=signal:cursor:
```

**Requires:**
- Redis connection configured in `config/database.php`
- Redis server running

### File Driver

Stores cursors in the filesystem (development only).

**Configuration:**
```php
'cursor_storage' => 'file',

'file' => [
    'path' => env('SIGNAL_FILE_PATH', storage_path('app/signal')),
],
```

**Environment Variables:**
```env
SIGNAL_CURSOR_STORAGE=file
SIGNAL_FILE_PATH=/path/to/storage/signal
```

**Not recommended for production:**
- Single server only
- No clustering support
- Filesystem I/O overhead

## Queue Configuration

Configure how Signal dispatches queued jobs.

### Queue Connection

```php
'queue' => [
    'connection' => env('SIGNAL_QUEUE_CONNECTION', null),
    'queue' => env('SIGNAL_QUEUE', 'default'),
],
```

**Environment Variables:**
```env
# Queue connection (redis, database, sqs, etc.)
SIGNAL_QUEUE_CONNECTION=redis

# Queue name
SIGNAL_QUEUE=signal
```

**Defaults:**
- `connection`: Uses Laravel's default queue connection
- `queue`: Uses Laravel's default queue name

### Per-Signal Configuration

Signals can override queue configuration:

```php
public function shouldQueue(): bool
{
    return true;
}

public function queueConnection(): string
{
    return 'redis'; // Override connection
}

public function queue(): string
{
    return 'high-priority'; // Override queue name
}
```

[Learn more about queue integration →](queues.md)

## Auto-Discovery

Configure automatic Signal discovery.

### Enable/Disable

```php
'auto_discovery' => [
    'enabled' => true,
    'path' => app_path('Signals'),
    'namespace' => 'App\\Signals',
],
```

**Options:**
- `enabled`: Enable/disable auto-discovery (default: `true`)
- `path`: Directory to scan for Signals (default: `app/Signals`)
- `namespace`: Namespace for discovered Signals (default: `App\Signals`)

### Disable Auto-Discovery

Manually register Signals instead:

```php
'auto_discovery' => [
    'enabled' => false,
],

'signals' => [
    \App\Signals\NewPostSignal::class,
    \App\Signals\NewFollowSignal::class,
],
```

### Custom Discovery Path

Organize Signals in a custom directory:

```php
'auto_discovery' => [
    'enabled' => true,
    'path' => app_path('Domain/Signals'),
    'namespace' => 'App\\Domain\\Signals',
],
```

## Manual Signal Registration

Register Signals explicitly.

### Configuration

```php
'signals' => [
    \App\Signals\NewPostSignal::class,
    \App\Signals\NewFollowSignal::class,
    \App\Signals\ProfileUpdateSignal::class,
],
```

**When to use:**
- Auto-discovery disabled
- Signals outside standard directory
- Fine-grained control over which Signals run

## Logging

Signal uses Laravel's logging system.

### Configure Logging

Standard Laravel log configuration applies:

```php
// config/logging.php
'channels' => [
    'signal' => [
        'driver' => 'daily',
        'path' => storage_path('logs/signal.log'),
        'level' => env('SIGNAL_LOG_LEVEL', 'debug'),
        'days' => 14,
    ],
],
```

Use in Signals:

```php
use Illuminate\Support\Facades\Log;

public function handle(SignalEvent $event): void
{
    Log::channel('signal')->info('Processing event', [
        'did' => $event->did,
    ]);
}
```

## Complete Configuration Reference

Here's the full `config/signal.php` with all options:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consumer Mode
    |--------------------------------------------------------------------------
    |
    | Choose between 'jetstream' (JSON events) or 'firehose' (CBOR/CAR events).
    | Jetstream provides simplified JSON events with server-side filtering.
    | Firehose provides raw CBOR/CAR data with comprehensive access.
    |
    | Options: 'jetstream', 'firehose'
    |
    */

    'mode' => env('SIGNAL_MODE', 'jetstream'),

    /*
    |--------------------------------------------------------------------------
    | Jetstream Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Jetstream mode (JSON events).
    |
    */

    'jetstream' => [
        'websocket_url' => env(
            'SIGNAL_JETSTREAM_URL',
            'wss://jetstream2.us-east.bsky.network'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firehose Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Firehose mode (CBOR/CAR events).
    |
    */

    'firehose' => [
        'host' => env('SIGNAL_FIREHOSE_HOST', 'bsky.network'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tap Configuration
    |--------------------------------------------------------------------------
    |
    | Tap is a Go binary service that delivers events via HTTP webhooks.
    | Enable it to receive events without a long-running PHP process.
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Cursor Storage
    |--------------------------------------------------------------------------
    |
    | Configure how Signal stores cursor positions for resuming after
    | disconnections. Options: 'database', 'redis', 'file'
    |
    */

    'cursor_storage' => env('SIGNAL_CURSOR_STORAGE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Redis cursor storage.
    |
    */

    'redis' => [
        'connection' => env('SIGNAL_REDIS_CONNECTION', 'default'),
        'key_prefix' => env('SIGNAL_REDIS_PREFIX', 'signal:cursor:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for file-based cursor storage.
    |
    */

    'file' => [
        'path' => env('SIGNAL_FILE_PATH', storage_path('app/signal')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue connection and name for processing events asynchronously.
    |
    */

    'queue' => [
        'connection' => env('SIGNAL_QUEUE_CONNECTION', null),
        'queue' => env('SIGNAL_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Discovery
    |--------------------------------------------------------------------------
    |
    | Automatically discover and register Signals from the specified directory.
    |
    */

    'auto_discovery' => [
        'enabled' => true,
        'path' => app_path('Signals'),
        'namespace' => 'App\\Signals',
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual Signal Registration
    |--------------------------------------------------------------------------
    |
    | Manually register Signals if auto-discovery is disabled.
    |
    */

    'signals' => [
        // \App\Signals\NewPostSignal::class,
    ],

];
```

## Environment-Specific Configuration

### Development

```env
SIGNAL_MODE=firehose
SIGNAL_CURSOR_STORAGE=file
SIGNAL_QUEUE_CONNECTION=sync
```

**Why:**
- Firehose mode sees all events (comprehensive testing)
- File storage is simple and adequate
- Sync queue processes immediately (easier debugging)

### Staging

```env
SIGNAL_MODE=jetstream
SIGNAL_CURSOR_STORAGE=redis
SIGNAL_QUEUE_CONNECTION=redis
SIGNAL_QUEUE=signal-staging
```

**Why:**
- Jetstream mode matches production
- Redis for performance testing
- Separate queue for staging isolation

### Production

```env
SIGNAL_MODE=jetstream
SIGNAL_CURSOR_STORAGE=database
SIGNAL_QUEUE_CONNECTION=redis
SIGNAL_QUEUE=signal
```

**Why:**
- Jetstream mode for efficiency
- Database storage for reliability
- Redis queues for performance

## Runtime Configuration

Change configuration at runtime:

```php
use SocialDept\AtpSignals\Facades\Signal;

// Override mode
config(['signal.mode' => 'firehose']);

// Override cursor storage
config(['signal.cursor_storage' => 'redis']);

// Start consumer with new config
Signal::start();
```

## Validation

Signal validates configuration on startup:

```bash
php artisan signal:consume
```

**Checks:**
- Mode is valid (`jetstream` or `firehose`)
- Cursor storage driver exists
- Required endpoints are configured
- Queue configuration is valid

**Validation errors prevent consumer from starting.**

## Configuration Helpers

### Check Current Mode

```php
$mode = config('signal.mode'); // 'jetstream' or 'firehose'
```

Or via Facade:

```php
use SocialDept\AtpSignals\Facades\Signal;

$mode = Signal::getMode();
```

### Check Cursor Storage

```php
$storage = config('signal.cursor_storage'); // 'database', 'redis', or 'file'
```

### Check Queue Configuration

```php
$connection = config('signal.queue.connection');
$queue = config('signal.queue.queue');
```

## Best Practices

### Use Environment Variables

Don't hardcode values in config file:

```php
// Good
'mode' => env('SIGNAL_MODE', 'jetstream'),

// Bad
'mode' => 'jetstream',
```

### Separate Staging and Production

Use different queues and storage:

```env
# .env.staging
SIGNAL_QUEUE=signal-staging

# .env.production
SIGNAL_QUEUE=signal-production
```

### Document Custom Configuration

If you change defaults, document why:

```php
// We use Firehose mode because we need raw CBOR/CAR data access
'mode' => env('SIGNAL_MODE', 'firehose'),
```

### Version Control

Commit `config/signal.php` but not `.env`:

```gitignore
# .gitignore
.env
.env.*

# Commit
config/signal.php
```

## Troubleshooting

### Configuration Not Loading

Clear config cache:

```bash
php artisan config:clear
php artisan config:cache
```

### Environment Variables Not Working

Check `.env` file exists and is readable:

```bash
ls -la .env
```

Restart services after changing `.env`:

```bash
# If using Supervisor
sudo supervisorctl restart signal-consumer:*
```

### Invalid Configuration

Run consumer to see validation errors:

```bash
php artisan signal:consume
```

Signal will display specific errors about misconfiguration.

## Next Steps

- **[Learn about testing →](testing.md)** - Test your configuration
- **[See real-world examples →](examples.md)** - Learn from production configurations
- **[Review queue integration →](queues.md)** - Configure queues optimally
