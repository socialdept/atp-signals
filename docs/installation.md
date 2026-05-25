# Installation

Signal is designed to be installed quickly and easily in any Laravel 11+ application.

## Requirements

Before installing Signal, ensure your environment meets these requirements:

- **PHP 8.2 or higher**
- **Laravel 11.0 or higher**
- **WebSocket support** (enabled by default in most environments)
- **Database** (for cursor storage)

## Composer Installation

Install Signal via Composer:

```bash
composer require socialdept/atp-signals
```

## Quick Setup

Run the installation command to set up everything automatically:

```bash
php artisan signal:install
```

This interactive command will:

1. Publish the configuration file to `config/signal.php`
2. Publish database migrations for cursor storage
3. Ask if you'd like to run migrations immediately
4. Display next steps and helpful information

### What Gets Created

After installation, you'll have:

- **Configuration file**: `config/signal.php` - All Signal settings
- **Migration**: `database/migrations/2024_01_01_000000_create_signal_cursors_table.php` - Cursor storage
- **Signal directory**: `app/Signals/` - Where your Signals live (created when you make your first Signal)

## Manual Installation

If you prefer more control, you can install manually:

### 1. Publish Configuration

```bash
php artisan vendor:publish --tag=signal-config
```

This creates `config/signal.php` with all available options.

### 2. Publish Migrations

```bash
php artisan vendor:publish --tag=signal-migrations
```

This creates the cursor storage migration in `database/migrations/`.

### 3. Run Migrations

```bash
php artisan migrate
```

This creates the `signal_cursors` table for resuming from last position after disconnections.

## Environment Configuration

Add Signal configuration to your `.env` file:

```env
# Consumer Mode (jetstream or firehose)
SIGNAL_MODE=jetstream

# Jetstream URL (if using jetstream mode)
SIGNAL_JETSTREAM_URL=wss://jetstream2.us-east.bsky.network

# Firehose Host (if using firehose mode)
SIGNAL_FIREHOSE_HOST=bsky.network

# Optional: Cursor Storage Driver (database, redis, or file)
SIGNAL_CURSOR_STORAGE=database

# Optional: Queue Configuration
SIGNAL_QUEUE_CONNECTION=redis
SIGNAL_QUEUE=signal
```

## Choosing Your Mode

Signal supports three modes for consuming events. Choose based on your use case:

### Jetstream Mode (Recommended)

Best for most applications:

```env
SIGNAL_MODE=jetstream
SIGNAL_JETSTREAM_URL=wss://jetstream2.us-east.bsky.network
```

**Advantages:**
- Simplified JSON events (easy to work with)
- Server-side collection filtering (efficient)
- Lower bandwidth and processing overhead

### Firehose Mode

Best for comprehensive indexing and raw data access:

```env
SIGNAL_MODE=firehose
SIGNAL_FIREHOSE_HOST=bsky.network
```

**Advantages:**
- Access to raw CBOR/CAR data
- Full AT Protocol event stream
- Complete control over event processing

**Trade-offs:**
- Client-side filtering only (higher bandwidth)
- More processing overhead

### Tap Mode

Best for production webhook delivery with automatic backfilling:

```env
TAP_ENABLED=true
TAP_URL=http://localhost:7374
TAP_ADMIN_PASSWORD=your-secret-password
```

**Advantages:**
- No long-running PHP process
- Automatic backfilling of historical events
- Process manager integration (Supervisor/systemd)

**Trade-offs:**
- Requires an external Go binary
- HTTP overhead per event

[Learn more about choosing the right mode →](modes.md) | [Learn more about Tap →](tap.md)

## Verify Installation

Check that Signal is installed correctly:

```bash
php artisan signal:list
```

This should display available Signals (initially none until you create them).

## Next Steps

Now that Signal is installed, you're ready to start building:

1. **[Create your first Signal →](quickstart.md)**
2. **[Learn about Signal architecture →](signals.md)**
3. **[Understand filtering options →](filtering.md)**

## Troubleshooting

### Migration Already Exists

If you see "migration already exists" when running `signal:install`, you've likely already installed Signal. You can safely skip this step.

### WebSocket Connection Issues

If you experience WebSocket connection issues:

1. Verify your firewall allows WebSocket connections
2. Check that your hosting environment supports WebSockets
3. Try switching Jetstream endpoints (US East vs US West)

### Permission Errors

If you encounter permission errors with cursor storage:

- **Database mode**: Ensure database connection is configured correctly
- **Redis mode**: Verify Redis connection is available
- **File mode**: Check that Laravel has write permissions to `storage/app/signal/`

## Uninstallation

To remove Signal from your application:

```bash
# Remove the package
composer remove socialdept/atp-signals

# Optionally, rollback migrations
php artisan migrate:rollback
```

You can also manually delete:
- `config/signal.php`
- `app/Signals/` directory
- Signal-related migrations
