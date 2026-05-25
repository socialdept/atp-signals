<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Signal Mode
    |--------------------------------------------------------------------------
    |
    | The mode determines which AT Protocol stream to consume:
    | - 'jetstream': JSON events with server-side collection filtering
    |                (supports all collections including third-party lexicons)
    | - 'firehose':  Raw CBOR events with client-side filtering
    |                (comprehensive access to all network events)
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
    | Tap Configuration
    |--------------------------------------------------------------------------
    |
    | Tap is a Go binary service from the AT Protocol team that provides
    | filtered, verified webhook delivery of repo events with automatic
    | backfilling. When enabled, Tap POSTs JSON events to your app's
    | webhook endpoint instead of requiring a persistent WebSocket.
    |
    */
    'tap' => [
        'enabled' => env('TAP_ENABLED', false),
        'database_path' => env('TAP_DATABASE_PATH', base_path('tap.db')),
        'base_url' => env('TAP_URL', 'http://localhost:7374'),
        'admin_password' => env('TAP_ADMIN_PASSWORD'),
        'webhook_path' => env('TAP_WEBHOOK_PATH', '/_atp/tap/webhook'),
        'webhook_middleware' => ['api'],
        'queue_events' => env('TAP_QUEUE_EVENTS', true),
        'queue_connection' => env('TAP_QUEUE_CONNECTION'),
        'queue_name' => env('TAP_QUEUE', 'tap'),
        'env_path' => env('TAP_ENV_PATH', storage_path('tap/env')),
        'restart_command' => env('TAP_RESTART_COMMAND'),
        // Optional batcher proxy. When enabled, Tap POSTs single events to
        // the batcher (a small Bun HTTP server), which buffers and forwards
        // batches to Laravel's TapBulkWebhookController. Useful when Tap's
        // per-event HTTP overhead is a bottleneck (e.g. during backfills).
        'batcher' => [
            'enabled' => env('TAP_BATCHER_ENABLED', false),
            'host' => env('TAP_BATCHER_HOST', '127.0.0.1'),
            'port' => (int) env('TAP_BATCHER_PORT', 9999),
            'path' => env('TAP_BATCHER_PATH', '/'),
            'batch_size' => (int) env('TAP_BATCH_SIZE', 500),
            'batch_timeout' => (int) env('TAP_BATCH_TIMEOUT', 5000),
            // Skip TLS verification on the batcher's outbound POST to the
            // Laravel bulk endpoint. Local-dev only (e.g. Herd's *.test certs).
            'insecure_tls' => env('TAP_BATCHER_INSECURE_TLS', false),
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
