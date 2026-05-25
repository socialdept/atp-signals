<?php

namespace SocialDept\AtpSignals\Tap\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SocialDept\AtpSignals\Tap\Concerns\PreventsWrites;

class TapRepo extends Model
{
    use PreventsWrites;

    protected $connection = 'tap';

    protected $table = 'repos';

    protected $primaryKey = 'did';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'retry_count' => 'integer',
            'retry_after' => 'integer',
        ];
    }

    /** Records indexed for this repo. */
    public function records(): HasMany
    {
        return $this->hasMany(TapRepoRecord::class, 'did', 'did');
    }

    /** Repos in "active" state (backfill complete, receiving live events). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', 'active');
    }

    /** Repos in "pending" state (awaiting backfill). */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('state', 'pending');
    }

    /** Repos with a specific status. */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Repos that have errored. */
    public function scopeErrored(Builder $query): Builder
    {
        return $query->whereNotNull('error_msg')->where('error_msg', '!=', '');
    }

    /** Repos eligible for retry (retry_after has passed). */
    public function scopeRetryable(Builder $query): Builder
    {
        return $query->where('retry_after', '<=', time())
            ->where('retry_count', '>', 0);
    }
}
