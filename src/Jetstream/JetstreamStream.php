<?php

namespace SocialDept\AtpSignals\Jetstream;

use Closure;
use InvalidArgumentException;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Streams\Stream;
use SocialDept\AtpSignals\Support\JetstreamV2Translator;

/**
 * A Jetstream v2 subscription for a caller that keeps its own position.
 * Batches hold SignalEvents, filtered on the server by collection, DID and
 * kind. Identity, account and sync events ignore the collection filter, as
 * the server sends them for every repo the DID filter allows.
 *
 * @see Stream for batching, reconnecting and the cursor
 */
class JetstreamStream extends Stream
{
    public const MAX_COLLECTIONS = 100;

    public const MAX_DIDS = 10000;

    public const KINDS = ['commit', 'identity', 'account', 'sync'];

    /**
     * @param  string  $url  The instance, such as wss://jetstream.us-east.bsky.network
     * @param  array<int, string>  $collections  NSIDs or prefix.* patterns, at most 100
     * @param  array<int, string>  $dids  Repos to receive, at most 10,000
     * @param  array<int, string>  $kinds  commit, identity, account, sync. Empty for all
     */
    public function __construct(
        protected string $url,
        protected array $collections = [],
        protected array $dids = [],
        protected array $kinds = [],
        int $batchSize = 100,
        float $batchWait = 0.25,
        int $idleTimeout = 120,
        int $maxBackoff = 60,
        ?Closure $connections = null,
    ) {
        parent::__construct($batchSize, $batchWait, $idleTimeout, $maxBackoff, $connections);

        $this->collections = array_values(array_unique(array_filter($collections, 'is_string')));
        $this->dids = array_values(array_unique(array_filter($dids, 'is_string')));
        $this->kinds = array_values(array_unique(array_filter($kinds, 'is_string')));

        if (count($this->collections) > self::MAX_COLLECTIONS) {
            throw new InvalidArgumentException('Jetstream takes at most '.self::MAX_COLLECTIONS.' collections, not '.count($this->collections).'.');
        }

        if (count($this->dids) > self::MAX_DIDS) {
            throw new InvalidArgumentException('Jetstream takes at most '.self::MAX_DIDS.' DIDs, not '.count($this->dids).'.');
        }

        if (($unknown = array_diff($this->kinds, self::KINDS)) !== []) {
            throw new InvalidArgumentException('Unknown Jetstream kinds: '.implode(', ', $unknown).'.');
        }

        // The server refuses a collections filter that could never match.
        if ($this->collections !== [] && $this->kinds !== [] && ! in_array('commit', $this->kinds, true)) {
            throw new InvalidArgumentException('A collections filter needs the commit kind.');
        }
    }

    /**
     * Each batch of events, with the last seq it holds.
     *
     * @param  Closure(array<int, SignalEvent>, int|null): void  $handler
     */
    public function onBatch(Closure $handler): static
    {
        return parent::onBatch($handler);
    }

    public function url(?int $cursor): string
    {
        $params = [];

        if ($cursor !== null && $cursor > 0) {
            $params[] = 'cursor='.$cursor;
        }

        foreach ($this->collections as $collection) {
            $params[] = 'collections='.str_replace('%2A', '*', rawurlencode($collection));
        }

        foreach ($this->dids as $did) {
            $params[] = 'dids='.rawurlencode($did);
        }

        foreach ($this->kinds as $kind) {
            $params[] = 'kinds='.$kind;
        }

        return rtrim($this->url, '/').'/xrpc/network.bsky.jetstream.subscribeEvents'.($params === [] ? '' : '?'.implode('&', $params));
    }

    protected function subProtocols(): array
    {
        return ['xrpc.v1.json'];
    }

    protected function decode(string $frame): array
    {
        $data = json_decode($frame, true);

        if (! is_array($data)) {
            return [];
        }

        $payload = JetstreamV2Translator::payload($data);

        if (JetstreamV2Translator::isInfo($payload)) {
            return ['info' => [(string) ($payload['name'] ?? ''), $payload['message'] ?? null]];
        }

        $event = JetstreamV2Translator::toSignalEvent($payload);

        return $event === null ? [] : ['items' => [$event], 'seq' => $event->seq];
    }
}
