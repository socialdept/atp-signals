<?php

namespace SocialDept\AtpSignals\Tests\Tap;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\SignalServiceProvider;
use SocialDept\AtpSignals\Tap\Models\TapRepo;
use SocialDept\AtpSignals\Tap\Models\TapRepoRecord;

class TapRepoModelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-signals.tap.enabled', true);
        $app['config']->set('database.connections.tap', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('tap')->statement('
            CREATE TABLE repos (
                did TEXT PRIMARY KEY,
                state TEXT NOT NULL DEFAULT "pending",
                status TEXT NOT NULL DEFAULT "active",
                handle TEXT,
                rev TEXT,
                prev_data TEXT,
                error_msg TEXT,
                retry_count INTEGER NOT NULL DEFAULT 0,
                retry_after INTEGER NOT NULL DEFAULT 0
            )
        ');

        DB::connection('tap')->statement('
            CREATE TABLE repo_records (
                did TEXT,
                collection TEXT,
                rkey TEXT,
                cid TEXT NOT NULL,
                PRIMARY KEY (did, collection, rkey)
            )
        ');

        DB::connection('tap')->table('repos')->insert([
            ['did' => 'did:plc:active1', 'state' => 'active', 'status' => 'active', 'handle' => 'alice.bsky.social', 'error_msg' => null, 'retry_count' => 0, 'retry_after' => 0],
            ['did' => 'did:plc:active2', 'state' => 'active', 'status' => 'active', 'handle' => 'bob.bsky.social', 'error_msg' => null, 'retry_count' => 0, 'retry_after' => 0],
            ['did' => 'did:plc:pending1', 'state' => 'pending', 'status' => 'active', 'handle' => 'charlie.bsky.social', 'error_msg' => null, 'retry_count' => 0, 'retry_after' => 0],
            ['did' => 'did:plc:errored1', 'state' => 'active', 'status' => 'active', 'handle' => 'dave.bsky.social', 'error_msg' => 'connection timeout', 'retry_count' => 3, 'retry_after' => 0],
            ['did' => 'did:plc:deactivated', 'state' => 'active', 'status' => 'deactivated', 'handle' => 'eve.bsky.social', 'error_msg' => null, 'retry_count' => 0, 'retry_after' => 0],
        ]);

        DB::connection('tap')->table('repo_records')->insert([
            ['did' => 'did:plc:active1', 'collection' => 'app.bsky.feed.post', 'rkey' => 'k1', 'cid' => 'cid1'],
            ['did' => 'did:plc:active1', 'collection' => 'app.bsky.feed.post', 'rkey' => 'k2', 'cid' => 'cid2'],
            ['did' => 'did:plc:active2', 'collection' => 'app.bsky.feed.like', 'rkey' => 'k3', 'cid' => 'cid3'],
        ]);
    }

    #[Test]    public function it_uses_the_tap_connection()
    {
        $repo = TapRepo::first();

        $this->assertEquals('tap', $repo->getConnectionName());
    }

    #[Test]    public function it_uses_did_as_primary_key()
    {
        $repo = TapRepo::find('did:plc:active1');

        $this->assertNotNull($repo);
        $this->assertEquals('did:plc:active1', $repo->did);
        $this->assertEquals('alice.bsky.social', $repo->handle);
    }

    #[Test]    public function it_scopes_active_repos()
    {
        $active = TapRepo::active()->get();

        $this->assertCount(4, $active);
        $this->assertTrue($active->pluck('did')->contains('did:plc:active1'));
    }

    #[Test]    public function it_scopes_pending_repos()
    {
        $pending = TapRepo::pending()->get();

        $this->assertCount(1, $pending);
        $this->assertEquals('did:plc:pending1', $pending->first()->did);
    }

    #[Test]    public function it_scopes_errored_repos()
    {
        $errored = TapRepo::errored()->get();

        $this->assertCount(1, $errored);
        $this->assertEquals('did:plc:errored1', $errored->first()->did);
    }

    #[Test]    public function it_scopes_with_status()
    {
        $deactivated = TapRepo::withStatus('deactivated')->get();

        $this->assertCount(1, $deactivated);
        $this->assertEquals('did:plc:deactivated', $deactivated->first()->did);
    }

    #[Test]    public function it_scopes_retryable_repos()
    {
        $retryable = TapRepo::retryable()->get();

        $this->assertCount(1, $retryable);
        $this->assertEquals('did:plc:errored1', $retryable->first()->did);
    }

    #[Test]    public function it_has_many_records()
    {
        $repo = TapRepo::find('did:plc:active1');
        $records = $repo->records;

        $this->assertCount(2, $records);
        $this->assertInstanceOf(TapRepoRecord::class, $records->first());
    }

    #[Test]    public function it_eager_loads_records()
    {
        $repo = TapRepo::with('records')->find('did:plc:active1');

        $this->assertTrue($repo->relationLoaded('records'));
        $this->assertCount(2, $repo->records);
    }

    #[Test]    public function it_casts_retry_fields_to_integer()
    {
        $repo = TapRepo::find('did:plc:errored1');

        $this->assertIsInt($repo->retry_count);
        $this->assertIsInt($repo->retry_after);
        $this->assertEquals(3, $repo->retry_count);
    }
}
