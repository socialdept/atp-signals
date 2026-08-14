<?php

namespace SocialDept\AtpSignals\Events;

use SocialDept\AtpSignals\Contracts\EventContract;

/**
 * A repo divergence marker (Jetstream v2 only): the account's records can no
 * longer be folded from the event stream alone and should be re-fetched from
 * its PDS. The v1 wire never emits this kind.
 */
class SyncEvent implements EventContract
{
    public function __construct(
        public string $did,
        public ?string $rev = null,
        public int $seq = 0,
        public ?string $time = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            did: $data['did'],
            rev: $data['rev'] ?? null,
            seq: $data['seq'] ?? 0,
            time: $data['time'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'did' => $this->did,
            'rev' => $this->rev,
            'seq' => $this->seq,
            'time' => $this->time,
        ];
    }
}
