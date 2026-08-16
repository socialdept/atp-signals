<?php

namespace SocialDept\AtpSignals\Support;

use SocialDept\AtpSignals\Events\AccountEvent;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\IdentityEvent;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Events\SyncEvent;

/**
 * Maps Jetstream v2 wire payloads onto SignalEvent.
 *
 * The v2 wire differs from v1: frames arrive as {"payload": {...}} envelopes
 * (xrpc.v1.json subprotocol), the payload carries a "$type" of
 * network.bsky.jetstream.subscribeEvents#<kind>, commit fields sit flat on the
 * payload instead of nested under "commit", the cursor is a "seq" instead of
 * "time_us", and a "sync" kind exists that v1 never emits.
 */
class JetstreamV2Translator
{
    protected const KINDS = ['commit', 'identity', 'account', 'sync'];

    /**
     * Extract the payload from a decoded v2 frame (tolerates unenveloped
     * payloads).
     */
    public static function payload(array $frame): array
    {
        $payload = $frame['payload'] ?? $frame;

        return is_array($payload) ? $payload : [];
    }

    /**
     * Whether the payload is a seq-less server advisory (#info frame).
     */
    public static function isInfo(array $payload): bool
    {
        return str_ends_with($payload['$type'] ?? '', '#info');
    }

    public static function toSignalEvent(array $payload): ?SignalEvent
    {
        $kind = self::kind($payload);

        if ($kind === null || ! isset($payload['did'])) {
            return null;
        }

        $commit = null;
        $identity = null;
        $account = null;
        $sync = null;

        switch ($kind) {
            case 'commit':
                if (! isset($payload['operation'], $payload['collection'], $payload['rkey'])) {
                    return null;
                }

                $commit = CommitEvent::fromArray([
                    'rev' => $payload['rev'] ?? '',
                    'operation' => $payload['operation'],
                    'collection' => $payload['collection'],
                    'rkey' => $payload['rkey'],
                    'record' => $payload['record'] ?? null,
                    'cid' => $payload['cid'] ?? null,
                ]);

                break;

            case 'identity':
                $identity = IdentityEvent::fromArray(self::nested($payload, 'identity'));

                break;

            case 'account':
                $account = self::nested($payload, 'account');

                if (! isset($account['active'])) {
                    return null;
                }

                $account = AccountEvent::fromArray($account);

                break;

            case 'sync':
                $sync = SyncEvent::fromArray(self::nested($payload, 'sync'));

                break;
        }

        return new SignalEvent(
            did: $payload['did'],
            timeUs: self::timeUs($payload['time'] ?? null) ?? 0,
            kind: $kind,
            commit: $commit,
            identity: $identity,
            account: $account,
            sync: $sync,
            seq: isset($payload['seq']) ? (int) $payload['seq'] : null,
        );
    }

    /**
     * Identity, account, and sync payloads nest their object under the kind's
     * key (commit fields sit flat). Falls back to the payload itself so a
     * flat variant still translates.
     */
    protected static function nested(array $payload, string $key): array
    {
        $inner = $payload[$key] ?? null;

        if (! is_array($inner) || $inner === []) {
            return $payload;
        }

        return $inner + ['did' => $payload['did']];
    }

    protected static function kind(array $payload): ?string
    {
        $type = $payload['$type'] ?? null;

        $kind = $type !== null && str_contains($type, '#')
            ? substr($type, strpos($type, '#') + 1)
            : ($payload['kind'] ?? null);

        return in_array($kind, self::KINDS, true) ? $kind : null;
    }

    protected static function timeUs(?string $time): ?int
    {
        if (! $time) {
            return null;
        }

        try {
            return (int) (new \DateTimeImmutable($time))->format('Uu');
        } catch (\Throwable) {
            return null;
        }
    }
}
