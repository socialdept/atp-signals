<?php

namespace SocialDept\AtpSignals\Storage;

use Illuminate\Support\Facades\Redis;
use SocialDept\AtpSignals\Contracts\CursorStore;

class RedisCursorStore implements CursorStore
{
    protected string $connection;
    protected string $key;

    /**
     * @param  string|null  $suffix  Appended to the configured key, so each consumer
     *                               mode keeps an independent position.
     */
    public function __construct(?string $suffix = null)
    {
        $this->connection = config('atp-signals.cursor_config.redis.connection', 'default');
        $this->key = config('atp-signals.cursor_config.redis.key', 'signal:cursor')
            .($suffix ? ":{$suffix}" : '');
    }

    public function get(): ?int
    {
        $cursor = Redis::connection($this->connection)->get($this->key);

        return $cursor ? (int) $cursor : null;
    }

    public function set(int $cursor): void
    {
        Redis::connection($this->connection)->set($this->key, $cursor);
    }

    public function clear(): void
    {
        Redis::connection($this->connection)->del($this->key);
    }
}
