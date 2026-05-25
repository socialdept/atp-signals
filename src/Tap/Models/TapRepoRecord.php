<?php

namespace SocialDept\AtpSignals\Tap\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SocialDept\AtpSignals\Tap\Concerns\PreventsWrites;

class TapRepoRecord extends Model
{
    use PreventsWrites;

    protected $connection = 'tap';

    protected $table = 'repo_records';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Eloquent does not support composite primary keys natively.
     * Use query scopes or findByKey() for lookups instead of Model::find().
     */
    protected $primaryKey = null;

    /** The repo this record belongs to. */
    public function repo(): BelongsTo
    {
        return $this->belongsTo(TapRepo::class, 'did', 'did');
    }

    /**
     * Find a specific record by its composite key.
     */
    public static function findByKey(string $did, string $collection, string $rkey): ?static
    {
        return static::query()
            ->where('did', $did)
            ->where('collection', $collection)
            ->where('rkey', $rkey)
            ->first();
    }

    /** Filter by DID. */
    public function scopeForDid(Builder $query, string $did): Builder
    {
        return $query->where('did', $did);
    }

    /** Filter by exact collection NSID. */
    public function scopeForCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /**
     * Filter by collection namespace prefix.
     * Accepts "com.offprint.*" or "com.offprint" — both work.
     */
    public function scopeInNamespace(Builder $query, string $namespace): Builder
    {
        $prefix = rtrim($namespace, '.*');

        return $query->where('collection', 'like', $prefix.'.%');
    }

    /** Filter by record key. */
    public function scopeForRkey(Builder $query, string $rkey): Builder
    {
        return $query->where('rkey', $rkey);
    }

    /**
     * Get record counts grouped by collection.
     * Returns rows with `collection` and `record_count` columns.
     */
    public function scopeCountByCollection(Builder $query): Builder
    {
        return $query->selectRaw('collection, count(*) as record_count')
            ->groupBy('collection')
            ->orderByDesc('record_count');
    }
}
