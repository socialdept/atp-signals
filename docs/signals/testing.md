# Testing Signals

Testing your Signals ensures they behave correctly before deploying to production. Signal provides tools for both manual and automated testing.

## Quick Testing with Artisan

The fastest way to test a Signal is with the `signal:test` command.

### Test a Signal

```bash
php artisan signal:test NewPostSignal
```

This runs your Signal with sample event data and displays the output.

### What It Does

1. Creates a sample `SignalEvent` matching your Signal's filters
2. Calls your Signal's `handle()` method
3. Displays output, logs, and any errors
4. Shows execution time

### Example Output

```
Testing Signal: App\Signals\NewPostSignal

Creating sample commit event for collection: app.bsky.feed.post
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Event Details:
  DID: did:plc:test123
  Collection: app.bsky.feed.post
  Operation: create
  Text: Sample post for testing

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Processing event...
✓ Signal processed successfully

Execution time: 12ms
```

### Limitations

- Uses sample data (not real events)
- Doesn't test filtering logic comprehensively
- Can't test queue behavior
- Limited to basic scenarios

For comprehensive testing, write automated tests.

## Unit Testing

Test your Signals in isolation.

### Basic Test Structure

```php
<?php

namespace Tests\Unit\Signals;

use App\Signals\NewPostSignal;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;
use Tests\TestCase;

class NewPostSignalTest extends TestCase
{
    /** @test */
    public function it_handles_new_posts()
    {
        $signal = new NewPostSignal();

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

        $signal->handle($event);

        // Assert expected behavior
        $this->assertDatabaseHas('posts', [
            'text' => 'Hello World!',
        ]);
    }
}
```

### Testing Event Types

Verify your Signal listens for correct event types:

```php
/** @test */
public function it_listens_for_commit_events()
{
    $signal = new NewPostSignal();

    $eventTypes = $signal->eventTypes();

    $this->assertContains('commit', $eventTypes);
}
```

### Testing Filters

Verify collection filtering:

```php
/** @test */
public function it_filters_to_posts_only()
{
    $signal = new NewPostSignal();

    $collections = $signal->collections();

    $this->assertEquals(['app.bsky.feed.post'], $collections);
}
```

### Testing Operation Filtering

```php
/** @test */
public function it_only_handles_creates()
{
    $signal = new NewPostSignal();

    $operations = $signal->operations();

    $this->assertEquals([SignalCommitOperation::Create], $operations);
}
```

### Testing Custom Filtering

```php
/** @test */
public function it_filters_posts_with_images()
{
    $signal = new ImagePostSignal();

    // Event with image
    $eventWithImage = $this->createEvent([
        'text' => 'Check this out!',
        'embed' => (object) ['type' => 'image'],
    ]);

    $this->assertTrue($signal->shouldHandle($eventWithImage));

    // Event without image
    $eventWithoutImage = $this->createEvent([
        'text' => 'Just text',
    ]);

    $this->assertFalse($signal->shouldHandle($eventWithoutImage));
}
```

## Feature Testing

Test Signals in the context of your application.

### Test with Database

```php
<?php

namespace Tests\Feature\Signals;

use App\Models\Post;
use App\Signals\StorePostSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;
use Tests\TestCase;

class StorePostSignalTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_stores_posts_in_database()
    {
        $signal = new StorePostSignal();

        $event = new SignalEvent(
            did: 'did:plc:test123',
            timeUs: time() * 1000000,
            kind: 'commit',
            commit: new CommitEvent(
                rev: 'abc',
                operation: 'create',
                collection: 'app.bsky.feed.post',
                rkey: 'test',
                record: (object) [
                    'text' => 'Test post',
                    'createdAt' => now()->toIso8601String(),
                ],
            ),
        );

        $signal->handle($event);

        $this->assertDatabaseHas('posts', [
            'did' => 'did:plc:test123',
            'text' => 'Test post',
        ]);
    }

    /** @test */
    public function it_updates_existing_posts()
    {
        Post::create([
            'did' => 'did:plc:test123',
            'rkey' => 'test',
            'text' => 'Old text',
        ]);

        $signal = new StorePostSignal();

        $event = $this->createUpdateEvent([
            'text' => 'New text',
        ]);

        $signal->handle($event);

        $this->assertDatabaseHas('posts', [
            'did' => 'did:plc:test123',
            'text' => 'New text',
        ]);

        $this->assertEquals(1, Post::count());
    }

    /** @test */
    public function it_deletes_posts()
    {
        Post::create([
            'did' => 'did:plc:test123',
            'rkey' => 'test',
            'text' => 'Test post',
        ]);

        $signal = new StorePostSignal();

        $event = $this->createDeleteEvent();

        $signal->handle($event);

        $this->assertDatabaseMissing('posts', [
            'did' => 'did:plc:test123',
            'rkey' => 'test',
        ]);
    }
}
```

### Test with External APIs

```php
use Illuminate\Support\Facades\Http;

/** @test */
public function it_sends_notifications()
{
    Http::fake();

    $signal = new NotificationSignal();

    $event = $this->createEvent();

    $signal->handle($event);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.example.com/notify' &&
               $request['text'] === 'New post created';
    });
}
```

## Testing Queued Signals

Test Signals that use queues.

### Test Queue Dispatch

```php
use Illuminate\Support\Facades\Queue;

/** @test */
public function it_queues_events()
{
    Queue::fake();

    $signal = new QueuedSignal();

    $this->assertTrue($signal->shouldQueue());

    // In production, this would queue
    // For testing, we verify the intent
}
```

### Test with Sync Queue

Process queued jobs synchronously in tests:

```php
/** @test */
public function it_processes_queued_events()
{
    // Use sync queue for immediate processing
    config(['queue.default' => 'sync']);

    $signal = new QueuedSignal();

    $event = $this->createEvent();

    $signal->handle($event);

    // Assert side effects happened
    $this->assertDatabaseHas('posts', [...]);
}
```

### Test Queue Configuration

```php
/** @test */
public function it_uses_high_priority_queue()
{
    $signal = new HighPrioritySignal();

    $this->assertTrue($signal->shouldQueue());
    $this->assertEquals('high-priority', $signal->queue());
}

/** @test */
public function it_uses_redis_connection()
{
    $signal = new RedisQueueSignal();

    $this->assertEquals('redis', $signal->queueConnection());
}
```

## Testing Failure Handling

Test how your Signal handles errors.

### Test Failed Method

```php
use Illuminate\Support\Facades\Log;

/** @test */
public function it_logs_failures()
{
    Log::spy();

    $signal = new FailureHandlingSignal();

    $event = $this->createEvent();
    $exception = new \Exception('Something went wrong');

    $signal->failed($event, $exception);

    Log::shouldHaveReceived('error')
        ->with('Signal failed', \Mockery::any());
}
```

### Test Exception Handling

```php
/** @test */
public function it_handles_invalid_data_gracefully()
{
    $signal = new RobustSignal();

    $event = new SignalEvent(
        did: 'did:plc:test',
        timeUs: time() * 1000000,
        kind: 'commit',
        commit: new CommitEvent(
            rev: 'test',
            operation: 'create',
            collection: 'app.bsky.feed.post',
            rkey: 'test',
            record: (object) [], // Missing required fields
        ),
    );

    // Should not throw
    $signal->handle($event);

    // Should handle gracefully (e.g., log and skip)
    $this->assertDatabaseCount('posts', 0);
}
```

## Test Helpers

Create reusable helpers for common test scenarios.

### Event Factory Helper

```php
trait CreatesSignalEvents
{
    protected function createCommitEvent(array $overrides = []): SignalEvent
    {
        $defaults = [
            'did' => 'did:plc:test123',
            'timeUs' => time() * 1000000,
            'kind' => 'commit',
            'commit' => new CommitEvent(
                rev: 'test',
                operation: $overrides['operation'] ?? 'create',
                collection: $overrides['collection'] ?? 'app.bsky.feed.post',
                rkey: $overrides['rkey'] ?? 'test',
                record: (object) array_merge([
                    'text' => 'Test post',
                    'createdAt' => now()->toIso8601String(),
                ], $overrides['record'] ?? []),
            ),
        ];

        return new SignalEvent(...$defaults);
    }

    protected function createPostEvent(array $record = []): SignalEvent
    {
        return $this->createCommitEvent([
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ]);
    }

    protected function createLikeEvent(array $record = []): SignalEvent
    {
        return $this->createCommitEvent([
            'collection' => 'app.bsky.feed.like',
            'record' => array_merge([
                'subject' => (object) [
                    'uri' => 'at://did:plc:test/app.bsky.feed.post/test',
                    'cid' => 'bafytest',
                ],
                'createdAt' => now()->toIso8601String(),
            ], $record),
        ]);
    }

    protected function createFollowEvent(array $record = []): SignalEvent
    {
        return $this->createCommitEvent([
            'collection' => 'app.bsky.graph.follow',
            'record' => array_merge([
                'subject' => 'did:plc:target',
                'createdAt' => now()->toIso8601String(),
            ], $record),
        ]);
    }
}
```

Use in tests:

```php
class MySignalTest extends TestCase
{
    use CreatesSignalEvents;

    /** @test */
    public function it_handles_posts()
    {
        $event = $this->createPostEvent([
            'text' => 'Custom text',
        ]);

        // Test with event
    }
}
```

### Signal Factory Helper

```php
trait CreatesSignals
{
    protected function createSignal(string $class, array $config = [])
    {
        $signal = new $class();

        // Override configuration for testing
        foreach ($config as $method => $value) {
            $signal->{$method} = $value;
        }

        return $signal;
    }
}
```

## Testing Best Practices

### Use Descriptive Test Names

```php
// Good
/** @test */
public function it_stores_posts_with_valid_data()

/** @test */
public function it_skips_posts_without_text()

/** @test */
public function it_handles_duplicate_posts_gracefully()

// Less descriptive
/** @test */
public function test_handle()
```

### Test Edge Cases

```php
/** @test */
public function it_handles_empty_text()
{
    $event = $this->createPostEvent(['text' => '']);
    // Test behavior
}

/** @test */
public function it_handles_very_long_text()
{
    $event = $this->createPostEvent(['text' => str_repeat('a', 10000)]);
    // Test behavior
}

/** @test */
public function it_handles_missing_created_at()
{
    $event = $this->createPostEvent(['createdAt' => null]);
    // Test behavior
}
```

### Test All Operations

```php
/** @test */
public function it_handles_creates()
{
    $event = $this->createEvent(['operation' => 'create']);
    // Test
}

/** @test */
public function it_handles_updates()
{
    $event = $this->createEvent(['operation' => 'update']);
    // Test
}

/** @test */
public function it_handles_deletes()
{
    $event = $this->createEvent(['operation' => 'delete']);
    // Test
}
```

### Mock External Dependencies

```php
/** @test */
public function it_calls_external_api()
{
    Http::fake([
        'api.example.com/*' => Http::response(['success' => true]),
    ]);

    $signal = new ApiSignal();
    $event = $this->createEvent();

    $signal->handle($event);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.example.com/endpoint';
    });
}
```

### Test Database State

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseSignalTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_records()
    {
        // Fresh database for each test
    }
}
```

## Continuous Integration

Run tests automatically on every commit.

### GitHub Actions

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2

      - name: Install Dependencies
        run: composer install

      - name: Run Tests
        run: php artisan test
```

### Run Signal-Specific Tests

```bash
# Run all Signal tests
php artisan test --testsuite=Signals

# Run specific test file
php artisan test tests/Unit/Signals/NewPostSignalTest.php

# Run with coverage
php artisan test --coverage
```

## Debugging Tests

### Enable Debug Output

```php
/** @test */
public function it_processes_events()
{
    $signal = new NewPostSignal();

    $event = $this->createEvent();

    dump($event); // Output event data

    $signal->handle($event);

    dump(Post::all()); // Output results
}
```

### Use dd() to Stop Execution

```php
/** @test */
public function it_processes_events()
{
    $event = $this->createEvent();

    dd($event); // Dump and die

    // This won't run
}
```

### Check Logs

```php
/** @test */
public function it_logs_processing()
{
    Log::spy();

    $signal = new LoggingSignal();
    $event = $this->createEvent();

    $signal->handle($event);

    Log::shouldHaveReceived('info')->once();
}
```

## Next Steps

- **[See real-world examples →](examples.md)** - Learn from production test patterns
- **[Review queue integration →](queues.md)** - Test queued Signals
- **[Review signals documentation →](signals.md)** - Understand Signal structure
