# Examples

Part of the [atp-signals docs](introduction.md#documentation). Signals for common jobs, from analytics and moderation to search indexing and a custom AppView.

## Social Media Analytics

Track engagement metrics across Bluesky.

```php
<?php

namespace App\Signals;

use App\Models\EngagementMetric;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EngagementTrackerSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return [
            'app.bsky.feed.post',
            'app.bsky.feed.like',
            'app.bsky.feed.repost',
            'app.bsky.graph.follow',
        ];
    }

    public function operations(): ?array
    {
        return [SignalCommitOperation::Create];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        $collection = $event->getCollection();
        $timestamp = Carbon::createFromTimestampMs(intdiv($event->timeUs, 1000));

        // Increment the counter for this hour
        DB::table('engagement_metrics')->upsert(
            [['collection' => $collection, 'hour' => $timestamp->startOfHour(), 'count' => 1, 'updated_at' => now()]],
            ['collection', 'hour'],
            ['count' => DB::raw('engagement_metrics.count + 1'), 'updated_at' => now()],
        );
    }
}
```

**Use case:** Analytics dashboards showing posts, likes, reposts and follows per hour.

## Content Moderation

Automatically flag problematic content.

```php
<?php

namespace App\Signals;

use App\Models\FlaggedPost;
use App\Services\ModerationService;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class ModerationSignal extends Signal
{
    public function __construct(
        private ModerationService $moderation
    ) {}

    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function operations(): ?array
    {
        return [SignalCommitOperation::Create];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function queue(): string
    {
        return 'moderation';
    }

    public function handle(SignalEvent $event): void
    {
        $record = $event->getRecord();

        if (!isset($record->text)) {
            return;
        }

        $result = $this->moderation->analyze($record->text);

        if ($result->needsReview) {
            FlaggedPost::create([
                'did' => $event->did,
                'rkey' => $event->commit->rkey,
                'text' => $record->text,
                'reason' => $result->reason,
                'confidence' => $result->confidence,
                'flagged_at' => now(),
            ]);
        }
    }

    public function failed(SignalEvent $event, \Throwable $exception): void
    {
        Log::error('Moderation signal failed', [
            'did' => $event->did,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

**Use case:** Automated content moderation with human review queue.

## User Activity Feed

Build a personalized activity feed.

```php
<?php

namespace App\Signals;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Carbon;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class ActivityFeedSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return [
            'app.bsky.feed.post',
            'app.bsky.feed.like',
            'app.bsky.feed.repost',
        ];
    }

    public function operations(): ?array
    {
        return [SignalCommitOperation::Create];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        // Check if we're tracking this user
        $user = User::where('did', $event->did)->first();

        if (!$user) {
            return;
        }

        // Check if any followers want to see this
        $followerIds = $user->followers()->pluck('id');

        if ($followerIds->isEmpty()) {
            return;
        }

        $collection = $event->getCollection();

        // Create activity for each follower's feed
        foreach ($followerIds as $followerId) {
            Activity::create([
                'user_id' => $followerId,
                'actor_did' => $event->did,
                'type' => $this->getActivityType($collection),
                'data' => $event->toArray(),
                'created_at' => Carbon::createFromTimestampMs(intdiv($event->timeUs, 1000)),
            ]);
        }
    }

    private function getActivityType(string $collection): string
    {
        return match ($collection) {
            'app.bsky.feed.post' => 'post',
            'app.bsky.feed.like' => 'like',
            'app.bsky.feed.repost' => 'repost',
            default => 'unknown',
        };
    }
}
```

**Use case:** Show users activity from people they follow.

## Real-Time Notifications

Send notifications for mentions and interactions.

```php
<?php

namespace App\Signals;

use App\Models\User;
use App\Notifications\MentionedInPost;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class MentionNotificationSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function operations(): ?array
    {
        return [SignalCommitOperation::Create];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        $record = $event->getRecord();

        if (!isset($record->facets)) {
            return;
        }

        // Extract mentions from facets. Nested record values are arrays.
        $mentions = collect($record->facets)
            ->flatMap(fn (array $facet) => $facet['features'] ?? [])
            ->filter(fn (array $feature) => ($feature['$type'] ?? null) === 'app.bsky.richtext.facet#mention')
            ->pluck('did')
            ->unique();

        foreach ($mentions as $mentionedDid) {
            $user = User::where('did', $mentionedDid)->first();

            if ($user) {
                $user->notify(new MentionedInPost(
                    authorDid: $event->did,
                    text: $record->text ?? '',
                    uri: "at://{$event->did}/app.bsky.feed.post/{$event->commit->rkey}"
                ));
            }
        }
    }
}
```

**Use case:** Real-time notifications when users are mentioned.

## Follow Tracker

Track follow relationships and send notifications.

```php
<?php

namespace App\Signals;

use App\Models\Follow;
use App\Models\User;
use App\Notifications\NewFollower;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class FollowTrackerSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.graph.follow'];
    }

    public function operations(): ?array
    {
        return [
            SignalCommitOperation::Create,
            SignalCommitOperation::Delete,
        ];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        $record = $event->getRecord();
        $operation = $event->getOperation();

        if ($operation === SignalCommitOperation::Create) {
            $this->handleNewFollow($event, $record);
        } else {
            $this->handleUnfollow($event);
        }
    }

    private function handleNewFollow(SignalEvent $event, object $record): void
    {
        Follow::create([
            'follower_did' => $event->did,
            'following_did' => $record->subject,
            'rkey' => $event->commit->rkey,
            'created_at' => $record->createdAt ?? now(),
        ]);

        // Notify the followed user
        $followedUser = User::where('did', $record->subject)->first();

        if ($followedUser) {
            $followedUser->notify(new NewFollower($event->did));
        }
    }

    private function handleUnfollow(SignalEvent $event): void
    {
        Follow::where('follower_did', $event->did)
            ->where('rkey', $event->commit->rkey)
            ->delete();
    }
}
```

**Use case:** Track follows and notify users of new followers.

## Search Indexer

Index posts for full-text search.

```php
<?php

namespace App\Signals;

use App\Models\Post;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class SearchIndexerSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function queue(): string
    {
        return 'indexing';
    }

    public function handle(SignalEvent $event): void
    {
        $operation = $event->getOperation();

        match ($operation) {
            SignalCommitOperation::Create,
            SignalCommitOperation::Update => $this->indexPost($event),
            SignalCommitOperation::Delete => $this->deletePost($event),
        };
    }

    private function indexPost(SignalEvent $event): void
    {
        $record = $event->getRecord();

        $post = Post::updateOrCreate(
            [
                'did' => $event->did,
                'rkey' => $event->commit->rkey,
            ],
            [
                'text' => $record->text ?? '',
                'created_at' => $record->createdAt ?? now(),
                'indexed_at' => now(),
            ]
        );

        // With Laravel Scout's Searchable trait on the model
        $post->searchable();
    }

    private function deletePost(SignalEvent $event): void
    {
        $post = Post::where('did', $event->did)
            ->where('rkey', $event->commit->rkey)
            ->first();

        if ($post) {
            $post->unsearchable();
            $post->delete();
        }
    }
}
```

**Use case:** Full-text search across all Bluesky posts.

## Trend Detection

Identify trending topics and hashtags.

```php
<?php

namespace App\Signals;

use App\Models\TrendingTopic;
use Illuminate\Support\Facades\Cache;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class TrendDetectionSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function operations(): ?array
    {
        return [SignalCommitOperation::Create];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        $record = $event->getRecord();

        if (!isset($record->text)) {
            return;
        }

        // Extract hashtags
        preg_match_all('/#(\w+)/', $record->text, $matches);

        foreach ($matches[1] as $hashtag) {
            $this->incrementHashtag($hashtag);
        }
    }

    private function incrementHashtag(string $hashtag): void
    {
        $key = "trending:hashtag:{$hashtag}";

        // Start the counter with a one hour expiry, then increment it
        Cache::add($key, 0, now()->addHour());
        $count = Cache::increment($key);

        // Update trending topics if threshold reached
        if ($count > 100) {
            TrendingTopic::updateOrCreate(
                ['hashtag' => $hashtag],
                ['count' => $count, 'updated_at' => now()]
            );
        }
    }
}
```

**Use case:** Identify trending hashtags as they happen.

## Custom AppView

Index custom collections for your AppView.

```php
<?php

namespace App\Signals;

use App\Models\Publication;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class PublicationIndexerSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return [
            'com.example.publication',
            'com.example.post',
        ];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        $collection = $event->getCollection();
        $operation = $event->getOperation();

        if ($collection === 'com.example.publication') {
            $this->handlePublication($event, $operation);
        } else {
            $this->handlePost($event, $operation);
        }
    }

    private function handlePublication(SignalEvent $event, SignalCommitOperation $operation): void
    {
        if ($operation === SignalCommitOperation::Delete) {
            Publication::where('did', $event->did)
                ->where('rkey', $event->commit->rkey)
                ->delete();
            return;
        }

        $record = $event->getRecord();

        Publication::updateOrCreate(
            [
                'did' => $event->did,
                'rkey' => $event->commit->rkey,
            ],
            [
                'title' => $record->title ?? '',
                'description' => $record->description ?? null,
                'created_at' => $record->createdAt ?? now(),
            ]
        );
    }

    private function handlePost(SignalEvent $event, SignalCommitOperation $operation): void
    {
        // Handle custom post records
    }
}
```

**Use case:** Build AT Protocol AppViews with custom collections.

## Rate-Limited API Integration

Integrate with external APIs respecting rate limits.

```php
<?php

namespace App\Signals;

use App\Services\ExternalAPIService;
use Illuminate\Support\Facades\RateLimiter;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class APIIntegrationSignal extends Signal
{
    public function __construct(
        private ExternalAPIService $api
    ) {}

    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.post'];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        $record = $event->getRecord();

        // Rate limit: 100 calls per minute
        $executed = RateLimiter::attempt(
            'external-api',
            $perMinute = 100,
            function () use ($event, $record) {
                $this->api->sendPost([
                    'author' => $event->did,
                    'text' => $record->text ?? '',
                    'time_us' => $event->timeUs,
                ]);
            }
        );

        if (!$executed) {
            // Re-queue for later
            dispatch(fn() => $this->handle($event))
                ->delay(now()->addMinutes(1));
        }
    }
}
```

**Use case:** Mirror content to external platforms with rate limiting.

## Multi-Collection Analytics

Track engagement across multiple collection types.

```php
<?php

namespace App\Signals;

use App\Models\UserMetrics;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

class UserMetricsSignal extends Signal
{
    public function eventTypes(): array
    {
        return ['commit'];
    }

    public function collections(): ?array
    {
        return ['app.bsky.feed.*', 'app.bsky.graph.*'];
    }

    public function operations(): ?array
    {
        return [SignalCommitOperation::Create];
    }

    public function shouldQueue(): bool
    {
        return true;
    }

    public function handle(SignalEvent $event): void
    {
        $collection = $event->getCollection();

        $metrics = UserMetrics::firstOrCreate(
            ['did' => $event->did],
            ['total_posts' => 0, 'total_likes' => 0, 'total_follows' => 0]
        );

        match ($collection) {
            'app.bsky.feed.post' => $metrics->increment('total_posts'),
            'app.bsky.feed.like' => $metrics->increment('total_likes'),
            'app.bsky.graph.follow' => $metrics->increment('total_follows'),
            default => null,
        };

        $metrics->touch('last_activity_at');
    }
}
```

**Use case:** User activity metrics and leaderboards.

## Performance Tips

### Use Queues for Heavy Operations

```php
public function shouldQueue(): bool
{
    return true;
}
```

### Add Indexes for Filtering

```php
// Migration for fast lookups
Schema::table('posts', function (Blueprint $table) {
    $table->index(['did', 'rkey']);
    $table->index('created_at');
});
```

## Next Steps

- **[Creating Signals](signals/signals.md)**: The Signal class and its methods.
- **[Filtering](signals/filtering.md)**: Every way to narrow the events a Signal handles.
- **[Queues](signals/queues.md)**: Handling events on a queue.
- **[Configuration](getting-started/configuration.md)**: Every option.
