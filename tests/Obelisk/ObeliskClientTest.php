<?php

namespace SocialDept\AtpSignals\Tests\Obelisk;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Obelisk\ObeliskClient;
use SocialDept\AtpSignals\SignalServiceProvider;

class ObeliskClientTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-signals.obelisk.base_url', 'http://obelisk.test');
        $app['config']->set('atp-signals.obelisk.token', 'test-token');
    }

    protected function client(): ObeliskClient
    {
        return $this->app->make(ObeliskClient::class);
    }

    #[Test]    public function it_calls_service_plane_queries_over_get_with_a_bearer_token()
    {
        Http::fake([
            '*' => Http::response(['events' => [], 'cursor' => null]),
        ]);

        $this->client()->getEvents(['cursor' => 42, 'collection' => 'site.standard.document']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'http://obelisk.test/xrpc/social.dept.obelisk.getEvents')
                && str_contains($request->url(), 'cursor=42')
                && str_contains($request->url(), 'include_record=1')
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    #[Test]    public function it_calls_service_plane_procedures_over_post()
    {
        Http::fake(['*' => Http::response(['webhook' => ['id' => 1, 'secret' => 'shh']])]);

        $result = $this->client()->createWebhook(['name' => 'app', 'url' => 'http://app.test/hook']);

        $this->assertSame('shh', $result['webhook']['secret']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'http://obelisk.test/xrpc/social.dept.obelisk.createWebhook'
                && $request['name'] === 'app';
        });
    }

    #[Test]    public function it_rewinds_a_subscription_cursor()
    {
        Http::fake(['*' => Http::response(['webhook' => ['id' => 7, 'cursor' => '0']])]);

        $this->client()->rewindWebhook(7, 0);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://obelisk.test/xrpc/social.dept.obelisk.updateWebhook'
                && $request['id'] === 7
                && $request['cursor'] === 0;
        });
    }

    #[Test]    public function it_calls_collection_plane_queries_over_post()
    {
        Http::fake(['*' => Http::response(['records' => []])]);

        $this->client()->searchRecords('site.standard.document', ['q' => 'atproto']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'http://obelisk.test/xrpc/site.standard.document.searchRecords'
                && $request['q'] === 'atproto';
        });
    }

    #[Test]    public function it_checks_health_on_the_unauthenticated_probe()
    {
        Http::fake(['http://obelisk.test/healthz' => Http::response('ok')]);

        $this->assertTrue($this->client()->healthy());
    }

    #[Test]    public function it_throws_on_an_error_response()
    {
        Http::fake(['*' => Http::response(['error' => 'InvalidRequest'], 400)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $this->client()->createWebhook(['name' => 'app']);
    }
}
