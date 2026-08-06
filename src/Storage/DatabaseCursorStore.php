<?php

namespace SocialDept\AtpSignals\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpSignals\Contracts\CursorStore;

class DatabaseCursorStore implements CursorStore
{
    protected string $table;
    protected ?string $connection;
    protected string $key;

    /**
     * @param  string|null  $key  Cursor row key, so each consumer mode keeps an
     *                            independent position. Defaults to 'jetstream'.
     */
    public function __construct(?string $key = null)
    {
        $this->table = config('atp-signals.cursor_config.database.table', 'signal_cursors');
        $this->connection = config('atp-signals.cursor_config.database.connection');
        $this->key = $key ?? 'jetstream';
    }

    public function get(): ?int
    {
        $cursor = $this->query()
            ->where('key', $this->key)
            ->value('cursor');

        // Compared against null, not truthiness: cursor 0 is a real position —
        // "replay from the very beginning" — and treating it as absent makes a
        // rewind to 0 indistinguishable from never having run.
        return $cursor === null ? null : (int) $cursor;
    }

    public function set(int $cursor): void
    {
        $this->query()
            ->updateOrInsert(
                ['key' => $this->key],
                [
                    'cursor' => $cursor,
                    'updated_at' => now(),
                ]
            );
    }

    public function clear(): void
    {
        $this->query()
            ->where('key', $this->key)
            ->delete();
    }

    protected function query(): Builder
    {
        return DB::connection($this->connection)
            ->table($this->table);
    }
}
