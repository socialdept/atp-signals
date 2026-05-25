<?php

namespace SocialDept\AtpSignals\Tests\Tap;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\SignalServiceProvider;
use SocialDept\AtpSignals\Tap\TapClient;

class TapClientTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-signals.tap.enabled', true);
        $app['config']->set('atp-signals.tap.base_url', 'http://localhost:7374');
        $app['config']->set('atp-signals.tap.admin_password', 'test-password');
    }

    #[Test]    public function it_can_add_a_repo()
    {
        Http::fake([
            'localhost:7374/repos/add' => Http::response([
                'did' => 'did:plc:test123',
                'status' => 'added',
            ]),
        ]);

        $client = new TapClient('http://localhost:7374', 'test-password');
        $result = $client->addRepo('did:plc:test123');

        $this->assertEquals('did:plc:test123', $result['did']);
        $this->assertEquals('added', $result['status']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:7374/repos/add'
                && $request['dids'] === ['did:plc:test123']
                && $request->hasHeader('Authorization');
        });
    }

    #[Test]    public function it_can_remove_a_repo()
    {
        Http::fake([
            'localhost:7374/repos/remove' => Http::response([
                'did' => 'did:plc:test123',
                'status' => 'removed',
            ]),
        ]);

        $client = new TapClient('http://localhost:7374', 'test-password');
        $result = $client->removeRepo('did:plc:test123');

        $this->assertEquals('did:plc:test123', $result['did']);
        $this->assertEquals('removed', $result['status']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:7374/repos/remove'
                && $request['dids'] === ['did:plc:test123'];
        });
    }

    #[Test]    public function it_can_check_health()
    {
        Http::fake([
            'localhost:7374/health' => Http::response([
                'status' => 'ok',
                'version' => '1.0.0',
            ]),
        ]);

        $client = new TapClient('http://localhost:7374', 'test-password');
        $result = $client->health();

        $this->assertEquals('ok', $result['status']);
    }

    #[Test]    public function it_throws_on_http_error()
    {
        Http::fake([
            'localhost:7374/repos/add' => Http::response(['error' => 'not found'], 404),
        ]);

        $client = new TapClient('http://localhost:7374', 'test-password');

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        $client->addRepo('did:plc:invalid');
    }

    #[Test]    public function it_sends_basic_auth_when_password_is_set()
    {
        Http::fake([
            'localhost:7374/health' => Http::response(['status' => 'ok']),
        ]);

        $client = new TapClient('http://localhost:7374', 'my-secret');
        $client->health();

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';

            return $auth === 'Basic '.base64_encode('admin:my-secret');
        });
    }

    #[Test]    public function it_works_without_password()
    {
        Http::fake([
            'localhost:7374/health' => Http::response(['status' => 'ok']),
        ]);

        $client = new TapClient('http://localhost:7374', null);
        $client->health();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:7374/health';
        });
    }

    #[Test]    public function it_is_resolved_from_container()
    {
        $client = $this->app->make(TapClient::class);

        $this->assertInstanceOf(TapClient::class, $client);
    }
}
