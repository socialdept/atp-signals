<?php

namespace SocialDept\AtpSignals\Tests\Tap;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\SignalServiceProvider;
use SocialDept\AtpSignals\Tap\Models\TapRepo;
use SocialDept\AtpSignals\Tap\Models\TapRepoRecord;

class TapRepoRecordModelTest extends TestCase
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
            ['did' => 'did:plc:user1', 'state' => 'active', 'status' => 'active', 'handle' => 'alice.bsky.social'],
            ['did' => 'did:plc:user2', 'state' => 'active', 'status' => 'active', 'handle' => 'bob.bsky.social'],
        ]);

        DB::connection('tap')->table('repo_records')->insert([
            ['did' => 'did:plc:user1', 'collection' => 'app.bsky.feed.post', 'rkey' => 'p1', 'cid' => 'cid1'],
            ['did' => 'did:plc:user1', 'collection' => 'app.bsky.feed.post', 'rkey' => 'p2', 'cid' => 'cid2'],
            ['did' => 'did:plc:user1', 'collection' => 'app.bsky.feed.like', 'rkey' => 'l1', 'cid' => 'cid3'],
            ['did' => 'did:plc:user1', 'collection' => 'com.offprint.publication', 'rkey' => 'pub1', 'cid' => 'cid4'],
            ['did' => 'did:plc:user1', 'collection' => 'com.offprint.document', 'rkey' => 'doc1', 'cid' => 'cid5'],
            ['did' => 'did:plc:user2', 'collection' => 'app.bsky.feed.post', 'rkey' => 'p3', 'cid' => 'cid6'],
            ['did' => 'did:plc:user2', 'collection' => 'com.offprint.publication', 'rkey' => 'pub2', 'cid' => 'cid7'],
        ]);
    }

    #[Test]    public function it_uses_the_tap_connection()
    {
        $record = TapRepoRecord::first();

        $this->assertEquals('tap', $record->getConnectionName());
    }

    #[Test]    public function it_scopes_for_did()
    {
        $records = TapRepoRecord::forDid('did:plc:user1')->get();

        $this->assertCount(5, $records);
    }

    #[Test]    public function it_scopes_for_collection()
    {
        $records = TapRepoRecord::forCollection('app.bsky.feed.post')->get();

        $this->assertCount(3, $records);
    }

    #[Test]    public function it_scopes_in_namespace_with_wildcard()
    {
        $records = TapRepoRecord::inNamespace('com.offprint.*')->get();

        $this->assertCount(3, $records);
    }

    #[Test]    public function it_scopes_in_namespace_without_wildcard()
    {
        $records = TapRepoRecord::inNamespace('com.offprint')->get();

        $this->assertCount(3, $records);
    }

    #[Test]    public function it_scopes_in_namespace_for_bsky()
    {
        $records = TapRepoRecord::inNamespace('app.bsky.feed.*')->get();

        $this->assertCount(4, $records);
    }

    #[Test]    public function it_scopes_for_rkey()
    {
        $records = TapRepoRecord::forRkey('p1')->get();

        $this->assertCount(1, $records);
        $this->assertEquals('did:plc:user1', $records->first()->did);
    }

    #[Test]    public function it_chains_multiple_scopes()
    {
        $records = TapRepoRecord::forDid('did:plc:user1')
            ->forCollection('app.bsky.feed.post')
            ->get();

        $this->assertCount(2, $records);
    }

    #[Test]    public function it_finds_by_composite_key()
    {
        $record = TapRepoRecord::findByKey('did:plc:user1', 'app.bsky.feed.post', 'p1');

        $this->assertNotNull($record);
        $this->assertEquals('cid1', $record->cid);
    }

    #[Test]    public function it_returns_null_for_missing_composite_key()
    {
        $record = TapRepoRecord::findByKey('did:plc:user1', 'app.bsky.feed.post', 'nonexistent');

        $this->assertNull($record);
    }

    #[Test]    public function it_counts_by_collection()
    {
        $stats = TapRepoRecord::countByCollection()->get();

        $this->assertCount(4, $stats);

        $postStat = $stats->firstWhere('collection', 'app.bsky.feed.post');
        $this->assertEquals(3, $postStat->record_count);

        $pubStat = $stats->firstWhere('collection', 'com.offprint.publication');
        $this->assertEquals(2, $pubStat->record_count);
    }

    #[Test]    public function it_belongs_to_repo()
    {
        $record = TapRepoRecord::forDid('did:plc:user1')->first();
        $repo = $record->repo;

        $this->assertInstanceOf(TapRepo::class, $repo);
        $this->assertEquals('alice.bsky.social', $repo->handle);
    }

    #[Test]    public function it_returns_all_records_count()
    {
        $this->assertEquals(7, TapRepoRecord::count());
    }
}
