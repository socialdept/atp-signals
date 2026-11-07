# Introduction

**ATP Signals** lets a Laravel application react to AT Protocol events as they happen, from posts, likes and follows on Bluesky to records in your own collections:

- **Signals:** classes that name the events they want and handle each one, discovered from `app/Signals` or registered in config.
- **Filtering:** by event type, collection (with wildcards such as `app.bsky.feed.*`), operation, DID, or any check of your own in `shouldHandle()`.
- **Three sources:** Jetstream (JSON, filtered on the server, v1 or v2), the firehose (CBOR, filtered in PHP), or an Obelisk archive (pushed by webhook or pulled, with replay).
- **Cursors:** the position is saved in the database, Redis or a file, so a restarted consumer resumes where it stopped, and the connection reconnects with backoff.
- **Queues:** a Signal that returns true from `shouldQueue()` is handled by a queued job, with `failed()` called when it throws.
- **Streams:** Jetstream v2 and labeler streams in batches, for code that keeps its own cursor.

## A first look

A Signal names what it listens for and handles each matching event:

```php
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class NewPostSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function handle(SignalEvent $event): void
    {
        logger()->info('New post', [
            'did' => $event->did,
            'text' => $event->getRecord()->text ?? null,
        ]);
    }
}
```

Generate one with artisan, then start the consumer:

```bash
php artisan make:signal NewPostSignal
php artisan signal:consume
```

## Documentation

Start with [Installation](getting-started/installation.md), then the [Quickstart](getting-started/quickstart.md) to build a first Signal. The docs are grouped by area, and each group has an index of its own:

- **[Getting started](getting-started/README.md)**: [Installation](getting-started/installation.md), [Quickstart](getting-started/quickstart.md), [Configuration](getting-started/configuration.md)
- **[Building signals](signals/README.md)**: [Creating Signals](signals/signals.md), [Filtering](signals/filtering.md), [Queues](signals/queues.md), [Testing](signals/testing.md)
- **[Event sources](sources/README.md)**: [Consumption modes](sources/modes.md), [Obelisk](sources/obelisk.md), [Streams](sources/streams.md)
- **[Examples](examples.md)**: Signals for common jobs, from analytics and moderation to search indexing and a custom AppView.
