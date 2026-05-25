<?php

namespace SocialDept\AtpSignals\Tests\Tap;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\SignalServiceProvider;
use SocialDept\AtpSignals\Tap\Exceptions\ReadOnlyException;
use SocialDept\AtpSignals\Tap\Models\TapRepo;
use SocialDept\AtpSignals\Tap\Models\TapRepoRecord;

class ReadOnlyTraitTest extends TestCase
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
            'did' => 'did:plc:test',
            'state' => 'active',
            'status' => 'active',
            'handle' => 'test.bsky.social',
        ]);
    }

    #[Test]    public function it_prevents_save_on_tap_repo()
    {
        $this->expectException(ReadOnlyException::class);

        $repo = new TapRepo();
        $repo->did = 'did:plc:new';
        $repo->save();
    }

    #[Test]    public function it_prevents_update_on_tap_repo()
    {
        $this->expectException(ReadOnlyException::class);

        $repo = TapRepo::first();
        $repo->update(['state' => 'pending']);
    }

    #[Test]    public function it_prevents_delete_on_tap_repo()
    {
        $this->expectException(ReadOnlyException::class);

        $repo = TapRepo::first();
        $repo->delete();
    }

    #[Test]    public function it_prevents_force_delete_on_tap_repo()
    {
        $this->expectException(ReadOnlyException::class);

        $repo = TapRepo::first();
        $repo->forceDelete();
    }

    #[Test]    public function it_prevents_destroy_on_tap_repo()
    {
        $this->expectException(ReadOnlyException::class);

        TapRepo::destroy('did:plc:test');
    }

    #[Test]    public function it_prevents_push_on_tap_repo()
    {
        $this->expectException(ReadOnlyException::class);

        $repo = TapRepo::first();
        $repo->push();
    }

    #[Test]    public function it_prevents_save_on_tap_repo_record()
    {
        $this->expectException(ReadOnlyException::class);

        $record = new TapRepoRecord();
        $record->did = 'did:plc:test';
        $record->collection = 'app.bsky.feed.post';
        $record->rkey = 'abc';
        $record->cid = 'bafyrei...';
        $record->save();
    }

    #[Test]    public function exception_message_includes_operation()
    {
        try {
            TapRepo::first()->delete();
            $this->fail('Expected ReadOnlyException');
        } catch (ReadOnlyException $e) {
            $this->assertStringContainsString('delete', $e->getMessage());
            $this->assertStringContainsString('read-only', $e->getMessage());
        }
    }
}
