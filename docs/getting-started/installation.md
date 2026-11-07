# Installation

Part of the [atp-signals docs](../introduction.md#documentation). Installing the package, its config and the cursor table.

## Installing

```bash
composer require socialdept/atp-signals
php artisan signal:install
```

`signal:install` publishes the config file and the cursor migration, then asks whether to run the migrations now. To do the same by hand:

```bash
php artisan vendor:publish --tag=atp-signals-config
php artisan vendor:publish --tag=signal-migrations
php artisan migrate
```

Signals live in `app/Signals`, which is created with the first `php artisan make:signal`. To check that the package is installed, list the Signals it has found:

```bash
php artisan signal:list
```

## Configuration

The config is published to `config/atp-signals.php` and read as `atp-signals.*`. The settings most apps change come from the environment:

```env
# Where events come from: jetstream, firehose or obelisk
SIGNAL_MODE=jetstream

# Jetstream (the default mode)
SIGNAL_JETSTREAM_URL=wss://jetstream2.us-east.bsky.network

# Firehose
SIGNAL_FIREHOSE_HOST=bsky.network

# Where the cursor is kept: database, redis or file
SIGNAL_CURSOR_STORAGE=database

# Queue for Signals that return true from shouldQueue()
SIGNAL_QUEUE_CONNECTION=redis
SIGNAL_QUEUE=signal
```

[Consumption modes](../sources/modes.md) explains how to choose a mode, and [Configuration](configuration.md) covers every option.

## Database

The package loads its migration itself, so `php artisan migrate` creates the `signal_cursors` table whether or not the migration was published. The table holds the position each consumer has reached, so a consumer that restarts picks up where it stopped. It is only used with the `database` cursor store. The `redis` store keeps the cursor under the `signal:cursor` key, and the `file` store in `storage/signal/cursor.json`.

## Troubleshooting

### WebSocket connection issues

If the consumer cannot connect, check that the host allows outbound WebSocket connections, and try the other Jetstream endpoint (`wss://jetstream1.us-west.bsky.network`).

### Cursor storage errors

- **Database store**: check that the database connection works and the migration has run.
- **Redis store**: check that the Redis connection is available.
- **File store**: check that Laravel can write to `storage/signal/`.

## Uninstalling

```bash
composer remove socialdept/atp-signals
```

Then delete `config/atp-signals.php`, the `app/Signals` directory and the published migration, and drop the `signal_cursors` table if you no longer need it.
