<?php

namespace SocialDept\AtpSignals\Storage;

use SocialDept\AtpSignals\Contracts\CursorStore;

class CursorStoreFactory
{
    /**
     * Build a cursor store for the configured driver.
     *
     * @param  string|null  $key  Namespace, so consumer modes keep independent
     *                            positions (null = the default 'jetstream' slot).
     */
    public static function make(?string $key = null): CursorStore
    {
        return match (config('atp-signals.cursor_storage')) {
            'redis' => new RedisCursorStore($key),
            'file' => new FileCursorStore($key),
            default => new DatabaseCursorStore($key),
        };
    }
}
