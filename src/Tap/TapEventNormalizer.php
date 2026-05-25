<?php

namespace SocialDept\AtpSignals\Tap;

use SocialDept\AtpSignals\Events\AccountEvent;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\IdentityEvent;
use SocialDept\AtpSignals\Events\SignalEvent;

class TapEventNormalizer
{
    /**
     * Normalize a Tap webhook JSON payload into a SignalEvent.
     *
     * Tap delivers events with this structure:
     *
     * Record event:
     * {
     *   "id": 12345,
     *   "type": "record",
     *   "record": {
     *     "live": true,
     *     "rev": "3kb3fge5lm32x",
     *     "did": "did:plc:abc123",
     *     "collection": "app.bsky.feed.post",
     *     "rkey": "3kb3fge5lm32x",
     *     "action": "create",
     *     "cid": "bafyreig...",
     *     "record": { "$type": "app.bsky.feed.post", "text": "Hello" }
     *   }
     * }
     *
     * Identity event:
     * {
     *   "id": 12346,
     *   "type": "identity",
     *   "identity": {
     *     "did": "did:plc:abc123",
     *     "handle": "alice.bsky.social",
     *     "isActive": true,
     *     "status": "active"
     *   }
     * }
     */
    public function normalize(array $data): SignalEvent
    {
        $type = $data['type'];

        return match ($type) {
            'record' => $this->normalizeRecord($data),
            'identity' => $this->normalizeIdentityEvent($data),
            default => throw new \InvalidArgumentException("Unknown Tap event type: {$type}"),
        };
    }

    protected function normalizeRecord(array $data): SignalEvent
    {
        $record = $data['record'];

        $commit = new CommitEvent(
            rev: $record['rev'] ?? '',
            operation: $record['action'],
            collection: $record['collection'],
            rkey: $record['rkey'],
            record: isset($record['record']) ? (object) $record['record'] : null,
            cid: $record['cid'] ?? null,
        );

        return new SignalEvent(
            did: $record['did'],
            timeUs: (int) (microtime(true) * 1_000_000),
            kind: 'commit',
            commit: $commit,
            backfill: ! ($record['live'] ?? true),
        );
    }

    protected function normalizeIdentityEvent(array $data): SignalEvent
    {
        $identity = $data['identity'];

        $identityEvent = new IdentityEvent(
            did: $identity['did'],
            handle: $identity['handle'] ?? null,
        );

        $account = new AccountEvent(
            did: $identity['did'],
            active: $identity['isActive'] ?? true,
            status: $identity['status'] ?? null,
        );

        return new SignalEvent(
            did: $identity['did'],
            timeUs: (int) (microtime(true) * 1_000_000),
            kind: 'identity',
            identity: $identityEvent,
            account: $account,
            backfill: false,
        );
    }
}
