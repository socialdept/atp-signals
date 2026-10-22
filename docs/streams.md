# Streams

Low-level subscriptions for code that keeps its own position, such as an indexer that saves the cursor in the same transaction as what it writes. Signals are the right tool for reacting to events one at a time. Streams are for taking the network in batches.

Two streams share one machinery:

- **`Jetstream\JetstreamStream`**: Jetstream v2's `subscribeEvents`, filtered on the server by collection, DID and kind. Batches hold `SignalEvent`s.
- **`Labels\LabelStream`**: a labeler's `com.atproto.label.subscribeLabels`. Batches hold labels as the labeler signed them.

## Jetstream

```php
use SocialDept\AtpSignals\Jetstream\JetstreamStream;

$stream = new JetstreamStream(
    'wss://jetstream.us-east.bsky.network',
    collections: ['com.example.post', 'com.example.like'],
    dids: [],                                   // up to 10,000 repos, empty for all
    kinds: ['commit', 'identity', 'account'],   // empty for all
);

$stream
    ->onBatch(function (array $events, ?int $seq) {
        DB::transaction(function () use ($events, $seq) {
            foreach ($events as $event) {
                // $event->did, $event->commit->collection, $event->commit->record, ...
            }

            Cursor::save($seq);
        });
    })
    ->onInfo(fn (string $name, ?string $message) => Log::info("Jetstream {$name}: {$message}"))
    ->onError(fn (Throwable $e) => Log::warning($e->getMessage()));

$stream->start(fn () => Cursor::load());   // blocks until $stream->stop()
```

- **Filters** follow the server's limits: at most 100 collections (NSIDs or `prefix.*`), at most 10,000 DIDs, and kinds from `commit`, `identity`, `account` and `sync`. A collections filter needs the `commit` kind. Anything else is refused when the stream is made, not by the server later.
- **Identity, account and sync events ignore the collection filter.** The server sends them for every repo the DID filter allows, so without a DID filter they come from the whole network, about one every two seconds.

## Labels

```php
use SocialDept\AtpSignals\Labels\LabelStream;

$stream = new LabelStream('https://mod.bsky.app');

$stream->onBatch(function (array $labels, ?int $seq) {
    foreach ($labels as $label) {
        // ver, src, uri, cid, val, neg, cts, exp, and sig: the 64 raw signature bytes
    }

    Cursor::save($seq);
});

$stream->start(fn () => Cursor::load());
```

Signatures are not checked here, since that needs the labeler's `#atproto_label` key from its DID document. Most of a labeler's stream is about accounts an app does not know, so check signatures only for the labels you keep: atp-support's `SignatureVerifier` verifies a label against the DAG-CBOR encoding of the label without its `sig` (atp-cbor's `CBOR::encode()`).

An error frame (`FutureCursor`, `ConsumerTooSlow`) reaches `onError`. An `#info` frame (`OutdatedCursor`) reaches `onInfo`.

## The cursor is yours

A stream asks for the cursor on every connect, by calling the closure given to `start()`. Return null to start at the live tip.

- **A batch handler that throws** closes the connection, and the stream reconnects from whatever the closure returns, so the failed batch comes again. Delivery is **at least once**: write idempotently, keyed on the record's AT-URI or the label's source, URI and value.
- **Save the cursor with the batch,** in the same transaction, and a crash between writing and saving can never skip or repeat work beyond that one batch.
- **A cursor the server no longer holds** is refused before the socket opens. For Jetstream, `CursorTooOld` arrives in `onError` with the oldest position it holds. Call `stop()` there and catch up another way, such as Jetstream's archive.

## Several streams in one process

`start()` runs the event loop and blocks. To follow several labelers, or Jetstream and labelers together, open each stream and run the loop once. `close()` ends one stream and leaves the others running, and `stop()` ends a stream and the loop:

```php
use React\EventLoop\Loop;

foreach ($labelers as $did => $endpoint) {
    (new LabelStream($endpoint))
        ->onBatch(fn (array $labels, ?int $seq) => $this->index($did, $labels, $seq))
        ->open(fn () => Cursor::load("labels:{$did}"));
}

Loop::run();
```

## Batching and connections

| Option | Default | |
| --- | --- | --- |
| `batchSize` | 100 | Items per batch at most |
| `batchWait` | 0.25 | Seconds a partial batch waits before it is handed over |
| `idleTimeout` | 120 | Seconds with neither a frame nor a pong before reconnecting. 0 turns it off |
| `maxBackoff` | 60 | The longest wait between reconnects, in seconds |

A stream never gives up reconnecting, backing off from one second up to `maxBackoff`. It pings a quiet connection and counts the pong as activity, so a stream that carries an item every few minutes is not mistaken for a dead one.

In a long-running process, run each stream under a supervisor, and restart it now and then if memory creeps.

## Cost

Measured on one stream, in one PHP process:

- Jetstream, `app.bsky.feed.like` at about 420 events a second: about 2% of one core and under 30 MB.
- Bluesky Moderation's label stream, catching up at about 2,000 labels a second while checking signatures only for labels about 18,000 known accounts: about 22% of one core and under 40 MB. A signature check costs about 2.4 ms in PHP, which is why only kept labels should be checked.
