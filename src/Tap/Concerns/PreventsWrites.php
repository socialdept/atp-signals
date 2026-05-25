<?php

namespace SocialDept\AtpSignals\Tap\Concerns;

use SocialDept\AtpSignals\Tap\Exceptions\ReadOnlyException;

trait PreventsWrites
{
    public static function bootPreventsWrites(): void
    {
        static::creating(fn () => throw new ReadOnlyException('create'));
        static::updating(fn () => throw new ReadOnlyException('update'));
        static::deleting(fn () => throw new ReadOnlyException('delete'));
    }

    /**
     * @return never
     */
    public function save(array $options = []): bool
    {
        throw new ReadOnlyException('save');
    }

    /**
     * @return never
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new ReadOnlyException('update');
    }

    /**
     * @return never
     */
    public function delete(): ?bool
    {
        throw new ReadOnlyException('delete');
    }

    /**
     * @return never
     */
    public function forceDelete(): ?bool
    {
        throw new ReadOnlyException('force delete');
    }

    /**
     * @return never
     */
    public static function destroy($ids): int
    {
        throw new ReadOnlyException('destroy');
    }

    /**
     * @return never
     */
    public function push(): bool
    {
        throw new ReadOnlyException('push');
    }
}
