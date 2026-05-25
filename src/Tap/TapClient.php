<?php

namespace SocialDept\AtpSignals\Tap;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TapClient
{
    protected string $baseUrl;

    protected ?string $adminPassword;

    public function __construct(?string $baseUrl = null, ?string $adminPassword = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('atp-signals.tap.base_url'), '/');
        $this->adminPassword = $adminPassword ?? config('atp-signals.tap.admin_password');
    }

    /**
     * Add repos to be tracked by Tap.
     *
     * @param  string|array<string>  $dids
     */
    public function addRepo(string|array $dids): array
    {
        $dids = is_string($dids) ? [$dids] : $dids;

        $response = $this->request()
            ->post("{$this->baseUrl}/repos/add", [
                'dids' => $dids,
            ]);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Remove repos from Tap tracking.
     *
     * @param  string|array<string>  $dids
     */
    public function removeRepo(string|array $dids): array
    {
        $dids = is_string($dids) ? [$dids] : $dids;

        $response = $this->request()
            ->post("{$this->baseUrl}/repos/remove", [
                'dids' => $dids,
            ]);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Check Tap service health.
     */
    public function health(): array
    {
        $response = $this->request()
            ->get("{$this->baseUrl}/health");

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Build an authenticated HTTP request.
     */
    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout(10);

        if ($this->adminPassword) {
            $request->withBasicAuth('admin', $this->adminPassword);
        }

        return $request;
    }
}
