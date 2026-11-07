[![Signal Header](./header.png)](https://github.com/socialdept/atp-signals)

<h3 align="center">
    Consume real-time AT Protocol events in your Laravel application.
</h3>

<p align="center">
    <br>
    <a href="https://packagist.org/packages/socialdept/atp-signals" title="Latest Version on Packagist"><img src="https://img.shields.io/packagist/v/socialdept/atp-signals.svg?style=flat-square"></a>
    <a href="https://packagist.org/packages/socialdept/atp-signals" title="Total Downloads"><img src="https://img.shields.io/packagist/dt/socialdept/atp-signals.svg?style=flat-square"></a>
    <a href="https://github.com/socialdept/atp-signals/actions/workflows/tests.yml" title="GitHub Tests Action Status"><img src="https://img.shields.io/github/actions/workflow/status/socialdept/atp-signals/tests.yml?branch=main&label=tests&style=flat-square"></a>
    <a href="LICENSE" title="Software License"><img src="https://img.shields.io/github/license/socialdept/atp-signals?style=flat-square"></a>
</p>

---

This package lets a Laravel application react to AT Protocol events as they happen. A Signal is a class that names the events it wants, by event type, collection, operation or DID, and handles each one, in the consumer process or on a queue. Events come from Jetstream, the firehose or an Obelisk archive, and the cursor is saved so a restarted consumer resumes where it stopped. For code that keeps its own cursor, Jetstream v2 and labeler streams deliver events and labels in batches. To learn all about it, head over to [the documentation](https://socialde.pt/docs/atp-signals).

Here are a few short examples of what you can do. Write a Signal that handles every new post:

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

Match several collections with a wildcard, and only the operations you care about:

```php
use SocialDept\AtpSignals\Enums\SignalCommitOperation;

public function collections(): ?array
{
    return ['app.bsky.feed.*', 'app.bsky.graph.*'];
}

public function operations(): ?array
{
    return [SignalCommitOperation::Create];
}
```

Handle events on a queue instead of in the consumer:

```php
public function shouldQueue(): bool
{
    return true;
}
```

Then start consuming:

```bash
php artisan signal:consume
```

## Documentation

You'll find the documentation on [https://socialde.pt/docs/atp-signals](https://socialde.pt/docs/atp-signals).

Find yourself stuck using the package? Found a bug? Do you have general questions or suggestions for improving it? Feel free to [create an issue on GitHub](https://github.com/socialdept/atp-signals/issues).

## Testing

```bash
vendor/bin/phpunit
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
