<?php

namespace SocialDept\AtpSignals\Commands\Concerns;

use SocialDept\AtpSignals\Obelisk\ObeliskClient;
use SocialDept\AtpSignals\Storage\CursorStoreFactory;

/**
 * Shared lookups for the `signal:obelisk:*` commands that act on one
 * subscription, addressed by `--id` or the friendlier `--name`.
 */
trait ResolvesObeliskSubscription
{
    /**
     * The subscription id from `--id`, or resolved from `--name`. Null (after
     * reporting the reason) when neither is usable.
     */
    protected function resolveSubscriptionId(ObeliskClient $client): ?int
    {
        if ($id = $this->option('id')) {
            return (int) $id;
        }

        $name = $this->option('name');

        if (! $name) {
            $this->components->error('Pass --id or --name.');

            return null;
        }

        try {
            $webhooks = $client->getWebhooks()['webhooks'] ?? [];
        } catch (\Throwable $e) {
            $this->components->error('Could not list subscriptions: '.$e->getMessage());

            return null;
        }

        foreach ($webhooks as $webhook) {
            if (($webhook['name'] ?? null) === $name) {
                return (int) $webhook['id'];
            }
        }

        $this->components->error("No subscription named \"{$name}\".");

        return null;
    }

    /**
     * Where the pull consumer has read up to, or null if it never ran.
     *
     * This is the handoff point between the two delivery modes: drain history
     * with `signal:obelisk:pull`, then start push delivery from here so the
     * archive replays only what came after, instead of the whole log.
     */
    protected function pullCursor(): ?int
    {
        return CursorStoreFactory::make('obelisk')->get();
    }
}
