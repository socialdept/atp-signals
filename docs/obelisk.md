# Obelisk Mode

[Obelisk](https://github.com/socialdept/obelisk) is a self-hostable AT Protocol record archive. It syncs configured collections from the network, keeps a permanent archive of every record it sees, and serves them over an authenticated XRPC API. Signal consumes its event log.

## Why Obelisk

Jetstream and Firehose give you the network as it happens and nothing else. If your process is down, those events are gone. Obelisk sits between your app and the network:

- **The archive is the buffer.** Events accumulate in a durable log. A consumer that has been down for a week resumes exactly where it stopped.
- **Server-side filtering that understands your lexicons.** Filter by collection, action, JSON field matchers, audience, or link-based feeds before anything reaches PHP.
- **Backpressure by construction.** Push delivery is batched: a full batch goes immediately, a partial batch at most once per `max_wait_ms`. Your endpoint is never flooded, including during a backfill.
- **Replay whenever you want.** Rewind a cursor and the same events come back, in order.

The trade is that you run Obelisk (a Bun process, Postgres, and Tab) alongside your app.

## Two ways to consume

Push and pull read the same event log and can run together.

| | Push (webhook) | Pull (`signal:consume`) |
|---|---|---|
| Direction | Obelisk POSTs to your app | Your app polls Obelisk |
| Needs an inbound URL | Yes | No |
| Needs a long-running process | No | Yes |
| Cursor lives in | Obelisk, per subscription | Your app, in the cursor store |
| Latency | Immediate | Up to `poll_interval` |
| Good for | Production | Local dev, catch-up, hosts that can't receive webhooks |

## Setup

### 1. Point Signal at your archive

```env
OBELISK_ENABLED=true
OBELISK_URL=http://localhost:6060
OBELISK_TOKEN=your-archive-bearer-token
```

Mint the token on the archive with `bun run scripts/create-token.ts my-app`.

### 2. Your Signals work unchanged

Obelisk's event log is commit events only — the archive has no identity or account stream. Everything else is the same:

```php
class IndexDocumentSignal extends Signal
{
    public function eventTypes(): array
    {
        return [SignalEventType::Commit];
    }

    public function collections(): ?array
    {
        return ['site.standard.document'];
    }

    public function handle(SignalEvent $event): void
    {
        // $event->cursor is the archive event id
        // $event->backfill is true for historical events
    }
}
```

### 3a. Push: create a subscription

```bash
php artisan signal:obelisk:subscribe                    # dry run, shows what it will do
php artisan signal:obelisk:subscribe --execute
```

The command derives the subscription name from your app name, the delivery URL from `APP_URL` plus the configured webhook path, and the collections from what your registered Signals declare. Override any of them:

```bash
php artisan signal:obelisk:subscribe \
    --name=my-app \
    --url=https://my-app.test/_atp/obelisk/webhook \
    --collections=site.standard.document \
    --collections=site.standard.publication \
    --from-cursor=start \
    --execute
```

Obelisk returns the signing secret **once**. Put it in your `.env`:

```env
OBELISK_WEBHOOK_SECRET=the-secret-it-printed
```

`--from-cursor=start` replays the archive's whole log. Omit it to receive only what happens next.

### 3b. Pull: run the consumer

```env
SIGNAL_MODE=obelisk
```

```bash
php artisan signal:consume
```

Or drain and exit, for a scheduled catch-up:

```bash
php artisan signal:obelisk:pull
```

```php
Schedule::command('signal:obelisk:pull')->everyMinute()->withoutOverlapping();
```

## How delivery stays correct

**Obelisk owns the push cursor.** It advances only on a 2xx response. The webhook endpoint answers 200 only once the batch is durably accepted — queued, or handled inline. Anything else (bad signature, malformed payload, queue failure) returns non-2xx, and Obelisk redelivers the same batch after an exponential backoff. Nothing is silently skipped.

**The batch is the unit of work.** `ProcessObeliskBatchJob` takes the whole delivery, not one job per event, so events keep their cursor order. A batch can contain a create, an update, and a delete for the same URI; dispatching them across parallel workers would let the wrong one land last.

Because of that, Signals consumed through Obelisk should leave `shouldQueue()` at `false` and let the batch job be the queue boundary. A Signal that returns `true` gets re-dispatched per event and gives up ordering.

**Delivery is at-least-once.** A redelivered batch replays events you may have already handled, so handlers must be idempotent — upsert on the record URI rather than insert.

## Verifying a delivery by hand

```php
$expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

hash_equals($expected, $request->header('X-Obelisk-Signature'));
```

Signal does this for you. It fails closed: with no `OBELISK_WEBHOOK_SECRET` set, deliveries are rejected rather than trusted.

## Event shape

Both planes carry the same event. Push wraps it in `{subscription, cursor, events: []}`; `getEvents` pages it.

| Field | Maps to | Notes |
|---|---|---|
| `did` | `$event->did` | |
| `collection` | `$event->commit->collection` | |
| `rkey` | `$event->commit->rkey` | |
| `action` | `$event->commit->operation` | `create` / `update` / `delete` |
| `cid` | `$event->commit->cid` | null on a delete |
| `rev` | `$event->commit->rev` | |
| `record` | `$event->commit->record` | null on a delete |
| `live` | `$event->backfill` | inverted: `live: false` means backfilled |
| `createdAt` | `$event->timeUs` | when the archive applied the change |
| `cursor` | `$event->cursor` | monotonic archive event id |

## Commands

| Command | What it does |
|---|---|
| `signal:obelisk:subscribe` | Create or update this app's webhook subscription |
| `signal:obelisk:status` | Archive reachability, subscriptions, cursors, failure counts |
| `signal:obelisk:rewind {cursor}` | Rewind a subscription (`--id`/`--name`) or the pull cursor (`--pull`) to replay |
| `signal:obelisk:pull` | Drain new events once and exit |
| `signal:consume` | Run the pull consumer continuously (`SIGNAL_MODE=obelisk`) |

`subscribe` and `rewind` are dry-run by default. Pass `--execute` to act.

## Replay

```bash
php artisan signal:obelisk:status                                   # find the id and cursor
php artisan signal:obelisk:rewind 0 --name=my-app --execute         # replay everything
php artisan signal:obelisk:rewind 4200 --name=my-app --execute      # replay from an event id
php artisan signal:obelisk:rewind 0 --pull --execute                # same, for pull mode
```

Records that predate the archive's event log have no events to replay. Seed them first, on the archive:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  "$OBELISK_URL/xrpc/social.dept.obelisk.backfillEvents" \
  -d '{"collection": "site.standard.document"}'
```

That is idempotent, and seeded events arrive with `live: false` so `$event->backfill` is true.

## Talking to the archive directly

`ObeliskClient` wraps the XRPC surface for anything beyond event consumption:

```php
use SocialDept\AtpSignals\Obelisk\ObeliskClient;

$client = app(ObeliskClient::class);

$client->getEvents(['cursor' => 0, 'collection' => 'site.standard.document']);
$client->searchRecords('site.standard.document', ['q' => 'atproto', 'semantic' => true]);
$client->addWatchedDid('did:plc:...');
$client->backfillRepo('did:plc:...');
$client->getBackfillStatus(['collection' => 'site.standard.document']);

// Escape hatches for anything not wrapped
$client->query('getFootprint', ['did' => 'did:plc:...']);
$client->procedure('createAudience', ['name' => 'subscribers', 'definition' => [...]]);
```

Obelisk never writes to a PDS, so there is no write path here. Publishing records is your app's job.

## Configuration

See [Configuration](configuration.md#obelisk-configuration) for every option.

## Troubleshooting

**Deliveries return 401.** The secret does not match. `OBELISK_WEBHOOK_SECRET` must be the value `createWebhook` returned; re-create the subscription if it was lost. Check `signal:obelisk:status` for a climbing failure count.

**Nothing arrives.** Confirm the subscription's URL is reachable *from Obelisk* — a container cannot resolve a host-only `.test` domain. Check `signal:obelisk:status`, and confirm the archive has events at all (`getEvents?cursor=0`).

**Events arrive but no Signal runs.** The subscription's collections and your Signals' `collections()` both filter. Set `SIGNAL_DEBUG=true` to log every event the batch processor sees.

**A subscription is marked `failing`.** 100 consecutive failures. Fix the cause, then reactivate: `updateWebhook {"id": N, "status": "active"}` clears the backoff. The cursor never moved, so nothing was lost.
