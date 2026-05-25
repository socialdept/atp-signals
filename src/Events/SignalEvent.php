<?php

namespace SocialDept\AtpSignals\Events;

use SocialDept\AtpSignals\Contracts\EventContract;

class SignalEvent implements EventContract
{
    public function __construct(
        public string $did,
        public int $timeUs,
        public string $kind, // 'commit', 'identity', 'account'
        public ?CommitEvent $commit = null,
        public ?IdentityEvent $identity = null,
        public ?AccountEvent $account = null,
        public ?bool $backfill = null, // null for jetstream/firehose, true/false for tap
    ) {
    }

    public function isCommit(): bool
    {
        return $this->kind === 'commit';
    }

    public function isIdentity(): bool
    {
        return $this->kind === 'identity';
    }

    public function isAccount(): bool
    {
        return $this->kind === 'account';
    }

    public function getCollection(): ?string
    {
        return $this->commit?->collection;
    }

    public function getRecord(): ?object
    {
        return $this->commit?->record;
    }

    public function getOperation(): ?\SocialDept\AtpSignals\Enums\SignalCommitOperation
    {
        return $this->commit?->operation;
    }

    public static function fromArray(array $data): self
    {
        $commit = isset($data['commit'])
            ? CommitEvent::fromArray($data['commit'])
            : null;

        $identity = isset($data['identity'])
            ? IdentityEvent::fromArray($data['identity'])
            : null;

        $account = isset($data['account'])
            ? AccountEvent::fromArray($data['account'])
            : null;

        return new self(
            did: $data['did'],
            timeUs: $data['time_us'],
            kind: $data['kind'],
            commit: $commit,
            identity: $identity,
            account: $account,
            backfill: $data['backfill'] ?? null,
        );
    }

    public function toArray(): array
    {
        $array = [
            'did' => $this->did,
            'time_us' => $this->timeUs,
            'kind' => $this->kind,
            'commit' => $this->commit?->toArray(),
            'identity' => $this->identity?->toArray(),
            'account' => $this->account?->toArray(),
        ];

        if ($this->backfill !== null) {
            $array['backfill'] = $this->backfill;
        }

        return $array;
    }
}
