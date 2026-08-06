<?php

namespace SocialDept\AtpSignals\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Storage\DatabaseCursorStore;

/**
 * Cursor 0 is a real position, not the absence of one.
 *
 * `get()` returned null for a stored 0 because 0 is falsy, so a rewind to the
 * start was indistinguishable from never having run — the two states an operator
 * most needs to tell apart at exactly the moment they are checking whether their
 * rewind took effect. The Redis store had the identical mistake; the file store
 * used `?? null` and was already correct.
 */
class DatabaseCursorStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'testing');

        Schema::create('signal_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('cursor')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    #[Test]
    public function it_reads_back_a_rewind_to_zero_as_zero_not_null()
    {
        $store = new DatabaseCursorStore('obelisk');
        $store->set(93458);

        $store->set(0);

        $this->assertSame(0, $store->get());
    }

    #[Test]
    public function it_still_reports_null_when_it_has_never_run()
    {
        $this->assertNull((new DatabaseCursorStore('obelisk'))->get());
    }

    #[Test]
    public function it_round_trips_an_ordinary_cursor()
    {
        $store = new DatabaseCursorStore('obelisk');
        $store->set(93458);

        $this->assertSame(93458, $store->get());
    }
}
