# Testing Signals

Part of the [atp-signals docs](../introduction.md#documentation). Testing Signals with the `signal:test` command and with PHPUnit.

## Quick Testing with Artisan

The `signal:test` command runs a Signal once against a built-in sample event.

```bash
php artisan signal:test NewPostSignal
```

The argument is a full class name, or a short name that is looked up under `App\Signals\`.

### What It Does

1. Builds a fixed sample commit event: a `create` on `app.bsky.feed.post` from `did:plc:sample123456789`, with a short text record
2. Prints the event as JSON
3. Calls your Signal's `shouldHandle()`, then `handle()` if it returned `true`
4. Prints a success line, or the exception message and a failure exit code

`--sample` picks the sample event. `commit` is the only sample available, and it is the default.

### Example Output

```
Testing signal: App\Signals\NewPostSignal

Sample event created:
{
    "did": "did:plc:sample123456789",
    "time_us": 1730000000000000,
    "kind": "commit",
    "commit": {
        "rev": "sample-rev",
        "operation": "create",
        "collection": "app.bsky.feed.post",
        "rkey": "sample-rkey",
        "record": {
            "text": "This is a sample post for testing",
            "createdAt": "2026-01-01T00:00:00+00:00"
        },
        "cid": "sample-cid"
    },
    "identity": null,
    "account": null
}

Calling signal->handle()...
✓ Signal executed successfully
```

If `shouldHandle()` returns `false`, it prints `Signal->shouldHandle() returned false` instead.

### Limitations

- The sample event is always the same post create, whatever your Signal listens for
- It skips the `eventTypes()`, `collections()`, `operations()` and `dids()` filters. Only `shouldHandle()` runs
- It calls `handle()` directly, even when `shouldQueue()` returns `true`, and never calls `failed()`

For anything beyond a smoke test, write PHPUnit tests.

## Building Events

A `SignalEvent` is a plain object you can construct directly. For commit events, pass a `CommitEvent`. The operation can be a string or a `SignalCommitOperation` case.

```php
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;

$event = new SignalEvent(
    did: 'did:plc:test123',
    timeUs: time() * 1000000,
    kind: 'commit',
    commit: new CommitEvent(
        rev: 'test',
        operation: 'create',
        collection: 'app.bsky.feed.post',
        rkey: 'test123',
        record: (object) [
            'text' => 'Hello World!',
            'createdAt' => now()->toIso8601String(),
        ],
    ),
);
```

A small trait keeps this out of each test:

```php
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;

trait CreatesSignalEvents
{
    protected function createCommitEvent(array $overrides = []): SignalEvent
    {
        return new SignalEvent(
            did: $overrides['did'] ?? 'did:plc:test123',
            timeUs: time() * 1000000,
            kind: 'commit',
            commit: new CommitEvent(
                rev: 'test',
                operation: $overrides['operation'] ?? 'create',
                collection: $overrides['collection'] ?? 'app.bsky.feed.post',
                rkey: $overrides['rkey'] ?? 'test',
                record: (object) array_merge([
                    'text' => 'Test post',
                    'createdAt' => now()->toIso8601String(),
                ], $overrides['record'] ?? []),
            ),
        );
    }
}
```

## Testing `handle()`

Call `handle()` directly and assert on its side effects:

```php
<?php

namespace Tests\Feature\Signals;

use App\Signals\StorePostSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorePostSignalTest extends TestCase
{
    use CreatesSignalEvents;
    use RefreshDatabase;

    public function test_it_stores_posts(): void
    {
        $event = $this->createCommitEvent(['record' => ['text' => 'Test post']]);

        app(StorePostSignal::class)->handle($event);

        $this->assertDatabaseHas('posts', [
            'did' => 'did:plc:test123',
            'text' => 'Test post',
        ]);
    }

    public function test_it_deletes_posts(): void
    {
        // Seed a post with rkey "test" first, then:
        $event = $this->createCommitEvent(['operation' => 'delete']);

        app(StorePostSignal::class)->handle($event);

        $this->assertDatabaseMissing('posts', [
            'did' => 'did:plc:test123',
            'rkey' => 'test',
        ]);
    }
}
```

Resolving the Signal with `app()` matches what the package does, so constructor dependencies are injected.

## Testing Filters

Calling `handle()` directly bypasses filtering. To check that your `eventTypes()`, `collections()` (including wildcards), `operations()`, `dids()` and `shouldHandle()` match the events you expect, register the Signal on a fresh `SignalRegistry` and ask it for matches:

```php
use App\Signals\NewPostSignal;
use SocialDept\AtpSignals\Services\SignalRegistry;

public function test_it_matches_post_creates_only(): void
{
    $registry = new SignalRegistry();
    $registry->register(NewPostSignal::class);

    $create = $this->createCommitEvent(['operation' => 'create']);
    $delete = $this->createCommitEvent(['operation' => 'delete']);
    $like = $this->createCommitEvent(['collection' => 'app.bsky.feed.like']);

    $this->assertCount(1, $registry->getMatchingSignals($create));
    $this->assertCount(0, $registry->getMatchingSignals($delete));
    $this->assertCount(0, $registry->getMatchingSignals($like));
}
```

`getMatchingSignals()` applies the same checks the consumer does, in the same order, ending with `shouldHandle()`.

## Testing Queued Signals

To test queueing, send the event through the `EventDispatcher` with the queue faked. The dispatcher pushes a `ProcessSignalJob` for each matching Signal whose `shouldQueue()` returns `true`:

```php
use Illuminate\Support\Facades\Queue;
use SocialDept\AtpSignals\Jobs\ProcessSignalJob;
use SocialDept\AtpSignals\Services\EventDispatcher;

public function test_it_queues_post_events(): void
{
    Queue::fake();

    app(EventDispatcher::class)->dispatch($this->createCommitEvent());

    Queue::assertPushedOn('signal', ProcessSignalJob::class);
}
```

The container's dispatcher uses the app's registry, which holds the Signals from `atp-signals.signals` plus auto-discovered ones, so every matching Signal runs. Signals that are not queued still call `handle()` inline.

To run the queued work in the test, use the `sync` queue connection. Queued Signals use your default connection unless `SIGNAL_QUEUE_CONNECTION` or `queueConnection()` says otherwise:

```php
public function test_it_processes_queued_events(): void
{
    config(['queue.default' => 'sync']);

    app(EventDispatcher::class)->dispatch($this->createCommitEvent());

    $this->assertDatabaseHas('posts', ['text' => 'Test post']);
}
```

To check a Signal's queue settings without dispatching, call the methods:

```php
$signal = app(HighPrioritySignal::class);

$this->assertTrue($signal->shouldQueue());
$this->assertSame('high-priority', $signal->queue());
```

## Testing Failure Handling

The dispatcher catches exceptions from a synchronous `handle()` and passes them to `failed()`. Calling `failed()` directly tests your own handling:

```php
use Illuminate\Support\Facades\Log;

public function test_it_logs_failures(): void
{
    Log::spy();

    $signal = app(FailureHandlingSignal::class);

    $signal->failed($this->createCommitEvent(), new \Exception('Something went wrong'));

    Log::shouldHaveReceived('error')->once();
}
```

## Next Steps

- **[See examples](../examples.md)**: complete Signals
- **[Review queue integration](queues.md)**: how queued Signals run
- **[Review signals documentation](signals.md)**: Signal structure
