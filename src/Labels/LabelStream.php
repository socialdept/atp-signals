<?php

namespace SocialDept\AtpSignals\Labels;

use Closure;
use RuntimeException;
use SocialDept\AtpCbor\Core\CBOR;
use SocialDept\AtpSignals\Streams\Stream;

/**
 * A labeler's com.atproto.label.subscribeLabels stream, for a caller that
 * keeps its own position. Batches hold labels as the labeler signed them,
 * `sig` included, so the caller can check signatures for the labels it keeps
 * and skip the rest, which is most of a labeler's stream.
 *
 * Signatures are not checked here: that needs the labeler's #atproto_label
 * key, which the caller resolves.
 *
 * @see Stream for batching, reconnecting and the cursor
 */
class LabelStream extends Stream
{
    /**
     * @param  string  $endpoint  The labeler's service, such as https://mod.bsky.app
     */
    public function __construct(
        protected string $endpoint,
        int $batchSize = 100,
        float $batchWait = 0.25,
        int $idleTimeout = 120,
        int $maxBackoff = 60,
        ?Closure $connections = null,
    ) {
        parent::__construct($batchSize, $batchWait, $idleTimeout, $maxBackoff, $connections);
    }

    /**
     * Each batch of labels, with the last seq it holds. A label is an array of
     * ver, src, uri, cid, val, neg, cts, exp and sig, as the labeler sent it.
     *
     * @param  Closure(array<int, array<string, mixed>>, int|null): void  $handler
     */
    public function onBatch(Closure $handler): static
    {
        return parent::onBatch($handler);
    }

    public function url(?int $cursor): string
    {
        $url = preg_replace('#^http(s?)://#', 'ws$1://', rtrim($this->endpoint, '/')).'/xrpc/com.atproto.label.subscribeLabels';

        return $cursor !== null && $cursor > 0 ? "{$url}?cursor={$cursor}" : $url;
    }

    /**
     * @throws RuntimeException for an error frame, such as FutureCursor or ConsumerTooSlow
     */
    protected function decode(string $frame): array
    {
        [$header, $body] = CBOR::decodeAll($frame) + [null, null];

        if (! is_array($header) || ! is_array($body)) {
            return [];
        }

        if (($header['op'] ?? null) === -1) {
            throw new RuntimeException('Labeler error '.($body['error'] ?? 'unknown').': '.($body['message'] ?? ''));
        }

        return match ($header['t'] ?? null) {
            '#labels' => ['items' => array_values(array_filter((array) ($body['labels'] ?? []), 'is_array')), 'seq' => isset($body['seq']) ? (int) $body['seq'] : null],
            '#info' => ['info' => [(string) ($body['name'] ?? ''), $body['message'] ?? null]],
            default => [],
        };
    }
}
