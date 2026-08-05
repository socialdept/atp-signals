<?php

namespace SocialDept\AtpSignals\Obelisk;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client for an Obelisk archive's XRPC surface.
 *
 * Two planes, following Obelisk's own split:
 *  - service plane, `social.dept.obelisk.{verb}` — the archive's own operations
 *    (events, webhooks, watched DIDs, backfills). Queries are GET, procedures POST.
 *  - collection plane, `{collection}.{verb}` — queries over an archived
 *    collection (getRecords, getRecord, countRecords, searchRecords), all POST.
 *
 * Obelisk never writes to a PDS, so there is no write path here.
 */
class ObeliskClient
{
    protected const SERVICE_NS = 'social.dept.obelisk';

    protected string $baseUrl;

    protected ?string $token;

    protected int $timeout;

    public function __construct(?string $baseUrl = null, ?string $token = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('atp-signals.obelisk.base_url', ''), '/');
        $this->token = $token ?? config('atp-signals.obelisk.token');
        $this->timeout = $timeout ?? (int) config('atp-signals.obelisk.timeout', 30);
    }

    // ── Events ───────────────────────────────────────────────

    /**
     * Cursor-paged change log. Returns `{events: [...], cursor: string|null}`.
     *
     * @param  array<string, mixed>  $params  cursor, collection, did, action, limit,
     *                                        since, until, order, audience, feed, …
     * @return array<string, mixed>
     */
    public function getEvents(array $params = []): array
    {
        return $this->query('getEvents', $params + ['include_record' => 1]);
    }

    /**
     * Seed synthetic events for archived records that predate the event log, so a
     * consumer starting at cursor 0 sees them. Idempotent.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function backfillEvents(array $input = []): array
    {
        return $this->procedure('backfillEvents', $input);
    }

    // ── Webhook subscriptions ────────────────────────────────

    /** @return array<string, mixed> */
    public function getWebhooks(): array
    {
        return $this->query('getWebhooks');
    }

    /**
     * Create a push subscription. The signing secret comes back exactly once.
     *
     * @param  array<string, mixed>  $input  name, url, collections, actions,
     *                                       record_matchers, audience, feed,
     *                                       include_record, max_events,
     *                                       max_wait_ms, from_cursor
     * @return array<string, mixed>
     */
    public function createWebhook(array $input): array
    {
        return $this->procedure('createWebhook', $input);
    }

    /**
     * @param  array<string, mixed>  $input  Must include `id`.
     * @return array<string, mixed>
     */
    public function updateWebhook(array $input): array
    {
        return $this->procedure('updateWebhook', $input);
    }

    /** @return array<string, mixed> */
    public function deleteWebhook(int $id): array
    {
        return $this->procedure('deleteWebhook', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    public function testWebhook(int $id): array
    {
        return $this->procedure('testWebhook', ['id' => $id]);
    }

    /**
     * Rewind (or fast-forward) a subscription's cursor to replay events.
     *
     * @return array<string, mixed>
     */
    public function rewindWebhook(int $id, int $cursor): array
    {
        return $this->updateWebhook(['id' => $id, 'cursor' => $cursor]);
    }

    // ── Repos ────────────────────────────────────────────────

    /**
     * Watch a DID across every collection (enrolls it for backfill + forward capture).
     *
     * @return array<string, mixed>
     */
    public function addWatchedDid(string $did, ?string $note = null, ?array $collections = null): array
    {
        return $this->procedure('addWatchedDid', array_filter([
            'did' => $did,
            'note' => $note,
            'collections' => $collections,
        ], fn ($value) => $value !== null));
    }

    /**
     * Re-index a repo from its PDS. Runs in the background on the archive.
     *
     * @return array<string, mixed>
     */
    public function backfillRepo(string $did, bool $all = false): array
    {
        return $this->procedure('backfillRepo', ['did' => $did, 'all' => $all]);
    }

    /** @return array<string, mixed> */
    public function getBackfillStatus(array $params = []): array
    {
        return $this->query('getBackfillStatus', $params);
    }

    // ── Records ──────────────────────────────────────────────

    /**
     * Query an archived collection. All collection-plane verbs are POST.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function getRecords(string $collection, array $input = []): array
    {
        return $this->collection($collection, 'getRecords', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function searchRecords(string $collection, array $input): array
    {
        return $this->collection($collection, 'searchRecords', $input);
    }

    // ── Health ───────────────────────────────────────────────

    public function healthy(): bool
    {
        return Http::timeout($this->timeout)->get("{$this->baseUrl}/healthz")->successful();
    }

    // ── Planes ───────────────────────────────────────────────

    /**
     * Service-plane query (GET).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function query(string $verb, array $params = []): array
    {
        $response = $this->request()->get($this->url(self::SERVICE_NS.'.'.$verb), $params);
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Service-plane procedure (POST).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function procedure(string $verb, array $body = []): array
    {
        $response = $this->request()->post($this->url(self::SERVICE_NS.'.'.$verb), $body);
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Collection-plane query (POST).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function collection(string $collection, string $verb, array $body = []): array
    {
        $response = $this->request()->post($this->url("{$collection}.{$verb}"), $body);
        $response->throw();

        return $response->json() ?? [];
    }

    protected function url(string $method): string
    {
        return "{$this->baseUrl}/xrpc/{$method}";
    }

    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()->timeout($this->timeout);

        if ($this->token) {
            $request->withToken($this->token);
        }

        return $request;
    }
}
