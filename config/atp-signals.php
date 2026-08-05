<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Signal Mode
    |--------------------------------------------------------------------------
    |
    | The mode determines where consumed events come from:
    | - 'jetstream': JSON events with server-side collection filtering
    |                (supports all collections including third-party lexicons)
    | - 'firehose':  Raw CBOR events with client-side filtering
    |                (comprehensive access to all network events)
    | - 'obelisk':   Pull an Obelisk archive's event log. Push delivery
    |                (webhooks) works independently of this setting.
    |
    */
    'mode' => env('SIGNAL_MODE', 'jetstream'),

    /*
    |--------------------------------------------------------------------------
    | Jetstream WebSocket URL
    |--------------------------------------------------------------------------
    |
    | The WebSocket URL for the AT Protocol Jetstream service.
    | US East: wss://jetstream2.us-east.bsky.network
    | US West: wss://jetstream1.us-west.bsky.network
    |
    */
    'websocket_url' => env('SIGNAL_JETSTREAM_URL', 'wss://jetstream2.us-east.bsky.network'),

    /*
    |--------------------------------------------------------------------------
    | Firehose Configuration
    |--------------------------------------------------------------------------
    |
    | Raw AT Protocol firehose settings.
    | Note: Firehose does NOT support server-side collection filtering.
    |
    */
    'firehose' => [
        'host' => env('SIGNAL_FIREHOSE_HOST', 'bsky.network'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Obelisk Configuration
    |--------------------------------------------------------------------------
    |
    | Obelisk is a self-hostable AT Protocol record archive that syncs
    | collections from the network and serves them over an authenticated XRPC
    | API. Signal consumes its event log two ways, and they can run together:
    |
    | - Push: Obelisk POSTs batched, HMAC-signed deliveries to the webhook
    |         route below. It owns the cursor and only advances it on a 2xx,
    |         so a failed delivery is redelivered rather than lost.
    | - Pull: `signal:consume` (mode 'obelisk') polls getEvents from a stored
    |         cursor. No inbound URL needed.
    |
    */
    'obelisk' => [
        'enabled' => env('OBELISK_ENABLED', false),

        // Archive base URL and the bearer token minted by its create-token script.
        'base_url' => env('OBELISK_URL', 'http://localhost:6060'),
        'token' => env('OBELISK_TOKEN'),
        'timeout' => (int) env('OBELISK_TIMEOUT', 30),

        // Push delivery. The secret is what createWebhook returned, once.
        'webhook_path' => env('OBELISK_WEBHOOK_PATH', '/_atp/obelisk/webhook'),
        'webhook_secret' => env('OBELISK_WEBHOOK_SECRET'),
        'webhook_middleware' => ['api'],

        // Signature verification. Leave on: with it off, anything that can reach
        // the route can inject events.
        'verify_signature' => env('OBELISK_VERIFY_SIGNATURE', true),

        // Queue the batch instead of handling it in the request. The batch is the
        // unit of work, which keeps events in cursor order.
        'queue_events' => env('OBELISK_QUEUE_EVENTS', true),
        'queue_connection' => env('OBELISK_QUEUE_CONNECTION'),
        'queue_name' => env('OBELISK_QUEUE', 'obelisk'),

        // Flow control. Refuse a delivery with 503 once this many jobs are
        // waiting, so the archive backs off instead of burying the queue.
        // Accepting is cheap and handling is not, so without this the archive
        // outruns the worker; each job carries a full batch of record bodies,
        // so memory gives out long before the job count looks alarming. Keep it
        // low for that reason. 0 disables the brake.
        'max_queue_depth' => (int) env('OBELISK_MAX_QUEUE_DEPTH', 100),

        // Pull consumer.
        'pull' => [
            // Events per getEvents page.
            'limit' => (int) env('OBELISK_PULL_LIMIT', 200),
            // Seconds to wait once the backlog is drained. A full page never waits.
            'poll_interval' => (int) env('OBELISK_PULL_POLL_INTERVAL', 5),
            // Optional single-collection filter. Null polls every archived
            // collection and lets each Signal's collections() do the filtering.
            'collection' => env('OBELISK_PULL_COLLECTION'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cursor Storage Driver
    |--------------------------------------------------------------------------
    |
    | Determines how Signal stores the cursor position for resuming after
    | disconnections. Options: 'database', 'redis', 'file'
    |
    */
    'cursor_storage' => env('SIGNAL_CURSOR_STORAGE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Cursor Storage Configuration
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Signals
    |--------------------------------------------------------------------------
    |
    | Register your Signals here, or use auto-discovery by placing them
    | in app/Signals directory.
    |
    */
    'signals' => [
        // App\Signals\PostCreateSignal::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Discovery
    |--------------------------------------------------------------------------
    |
    | Automatically discover Signals in the specified directory.
    |
    */
    'auto_discovery' => [
        'enabled' => true,
        'path' => app_path('Signals'),
        'namespace' => 'App\\Signals',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Default queue settings for Signals that should be queued.
    |
    */
    'queue' => [
        'connection' => env('SIGNAL_QUEUE_CONNECTION'),
        'queue' => env('SIGNAL_QUEUE', 'signal'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Settings
    |--------------------------------------------------------------------------
    */
    'connection' => [
        'reconnect_attempts' => 5,
        'reconnect_delay' => 5, // Base delay in seconds (exponential backoff)
        'max_reconnect_delay' => 60, // Maximum delay in seconds
        'timeout' => 60, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance & Rate Limiting
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'batch_size' => env('SIGNAL_BATCH_SIZE', 100),
        'rate_limit' => env('SIGNAL_RATE_LIMIT', 1000), // events per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('SIGNAL_LOG_CHANNEL', 'stack'),
        'level' => env('SIGNAL_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, logs debug information about signal handling including
    | which signals are matched and dispatched for incoming events.
    |
    */
    'debug' => env('SIGNAL_DEBUG', false),
];
