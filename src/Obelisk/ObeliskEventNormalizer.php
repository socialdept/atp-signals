<?php

namespace SocialDept\AtpSignals\Obelisk;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;

class ObeliskEventNormalizer
{
    /**
     * Normalize one Obelisk archive event into a SignalEvent.
     *
     * Obelisk emits the same shape on both planes — batched webhook deliveries
     * wrap it in `{subscription, cursor, events: []}`, `getEvents` pages it:
     *
     * {
     *   "cursor": "1234",
     *   "uri": "at://did:plc:abc123/site.standard.document/3lab",
     *   "did": "did:plc:abc123",
     *   "collection": "site.standard.document",
     *   "rkey": "3lab",
     *   "action": "create",
     *   "cid": "bafyreig...",
     *   "rev": "3kb3fge5lm32x",
     *   "live": true,
     *   "createdAt": "2026-08-04T12:00:00.000Z",
     *   "record": { "$type": "site.standard.document", "title": "Hello" }
     * }
     *
     * Only commit events exist in the archive's event log — Obelisk has no
     * identity or account stream, so `kind` is always 'commit'.
     *
     * @param  array<string, mixed>  $event
     */
    public function normalize(array $event): SignalEvent
    {
        foreach (['did', 'collection', 'rkey', 'action'] as $field) {
            if (! isset($event[$field]) || ! is_string($event[$field]) || $event[$field] === '') {
                throw new InvalidArgumentException("Obelisk event is missing required field: {$field}");
            }
        }

        $commit = new CommitEvent(
            rev: (string) ($event['rev'] ?? ''),
            operation: $event['action'],
            collection: $event['collection'],
            rkey: $event['rkey'],
            record: is_array($event['record'] ?? null) ? (object) $event['record'] : null,
            cid: $event['cid'] ?? null,
        );

        return new SignalEvent(
            did: $event['did'],
            timeUs: $this->timeUs($event['createdAt'] ?? null),
            kind: 'commit',
            commit: $commit,
            // Obelisk marks historical/backfilled events `live: false`.
            backfill: ! ($event['live'] ?? true),
            cursor: isset($event['cursor']) ? (string) $event['cursor'] : null,
        );
    }

    /**
     * Microsecond timestamp from the archive's `createdAt`, falling back to now.
     */
    protected function timeUs(?string $createdAt): int
    {
        if (! $createdAt) {
            return (int) (microtime(true) * 1_000_000);
        }

        try {
            return (int) Carbon::parse($createdAt)->getPreciseTimestamp(6);
        } catch (\Throwable) {
            return (int) (microtime(true) * 1_000_000);
        }
    }
}
