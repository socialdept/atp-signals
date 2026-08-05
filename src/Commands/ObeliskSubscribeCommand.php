<?php

namespace SocialDept\AtpSignals\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpSignals\Commands\Concerns\ResolvesObeliskSubscription;
use SocialDept\AtpSignals\Obelisk\ObeliskClient;
use SocialDept\AtpSignals\Services\SignalRegistry;

class ObeliskSubscribeCommand extends Command
{
    use ResolvesObeliskSubscription;

    protected $signature = 'signal:obelisk:subscribe
                            {--name= : Subscription name (defaults to the app name, slugged)}
                            {--url= : Webhook URL Obelisk should POST to (defaults to the app URL + configured path)}
                            {--collections=* : Collections to subscribe to (defaults to those your Signals declare)}
                            {--from-cursor= : Start position — "pull" to continue where signal:obelisk:pull stopped, "start" for the whole log, or an event id (default: only new events)}
                            {--max-events=200 : Events per delivery}
                            {--max-wait-ms=5000 : How long a partial batch waits before delivery}
                            {--execute : Actually create or update the subscription}';

    protected $description = 'Create or update this app\'s webhook subscription on an Obelisk archive';

    public function handle(ObeliskClient $client, SignalRegistry $registry): int
    {
        $name = $this->option('name') ?: str(config('app.name', 'laravel'))->slug()->value();
        $url = $this->option('url') ?: rtrim(config('app.url'), '/')
            .config('atp-signals.obelisk.webhook_path', '/_atp/obelisk/webhook');

        $collections = $this->option('collections') ?: $this->collectionsFromSignals($registry);

        $payload = array_filter([
            'name' => $name,
            'url' => $url,
            'collections' => $collections,
            'max_events' => (int) $this->option('max-events'),
            'max_wait_ms' => (int) $this->option('max-wait-ms'),
            'from_cursor' => $this->fromCursor(),
        ], fn ($value) => $value !== null);

        $this->components->twoColumnDetail('Archive', (string) config('atp-signals.obelisk.base_url'));
        $this->components->twoColumnDetail('Subscription', $name);
        $this->components->twoColumnDetail('Delivers to', $url);
        $this->components->twoColumnDetail('Collections', $collections ? implode(', ', $collections) : 'all archived collections');
        $this->components->twoColumnDetail('Batching', "{$payload['max_events']} events / {$payload['max_wait_ms']}ms");
        $this->components->twoColumnDetail('From cursor', (string) ($payload['from_cursor'] ?? 'now (new events only)'));

        if (! $this->option('execute')) {
            $this->newLine();
            $this->components->warn('Dry run. Re-run with --execute to create or update the subscription.');

            return self::SUCCESS;
        }

        try {
            $existing = $this->findExisting($client, $name);

            if ($existing) {
                $client->updateWebhook(['id' => $existing['id']] + $payload);
                $this->newLine();
                $this->components->info("Updated subscription \"{$name}\" (id {$existing['id']}).");
                $this->line('  The signing secret is unchanged — OBELISK_WEBHOOK_SECRET still applies.');

                return self::SUCCESS;
            }

            $result = $client->createWebhook($payload);
        } catch (\Throwable $e) {
            $this->components->error('Obelisk rejected the request: '.$e->getMessage());

            return self::FAILURE;
        }

        $secret = $result['webhook']['secret'] ?? null;

        $this->newLine();
        $this->components->info("Created subscription \"{$name}\".");

        if ($secret) {
            $this->newLine();
            $this->line('  Obelisk returns the signing secret once. Add it to your .env now:');
            $this->newLine();
            $this->line("  <options=bold>OBELISK_WEBHOOK_SECRET={$secret}</>");
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Union of every collection the registered Signals declare. A Signal that
     * listens to everything (null) widens the subscription to everything.
     *
     * @return array<int, string>
     */
    protected function collectionsFromSignals(SignalRegistry $registry): array
    {
        $collections = [];

        foreach ($registry->all() as $signal) {
            if ($signal->collections() === null) {
                return [];
            }

            $collections = array_merge($collections, $signal->collections());
        }

        return array_values(array_unique($collections));
    }

    /**
     * Resolve `--from-cursor` to what the archive expects.
     *
     * `pull` is the handoff from a drained backfill: start push delivery exactly
     * where the pull consumer stopped, so the archive replays only what arrived
     * after it, rather than the entire log.
     */
    protected function fromCursor(): string|int|null
    {
        $value = $this->option('from-cursor');

        if ($value === null) {
            return null;
        }

        if ($value === 'start') {
            return 'start';
        }

        if ($value === 'pull') {
            $cursor = $this->pullCursor();

            if ($cursor === null) {
                $this->components->warn('No stored pull cursor; falling back to new events only.');

                return null;
            }

            return $cursor;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findExisting(ObeliskClient $client, string $name): ?array
    {
        $webhooks = $client->getWebhooks()['webhooks'] ?? [];

        foreach ($webhooks as $webhook) {
            if (($webhook['name'] ?? null) === $name) {
                return $webhook;
            }
        }

        return null;
    }
}
