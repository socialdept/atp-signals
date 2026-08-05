<?php

namespace SocialDept\AtpSignals\Tests\Obelisk;

use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Enums\SignalCommitOperation;
use SocialDept\AtpSignals\Obelisk\ObeliskEventNormalizer;
use SocialDept\AtpSignals\SignalServiceProvider;

class ObeliskEventNormalizerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SignalServiceProvider::class];
    }

    protected function event(array $overrides = []): array
    {
        return array_merge([
            'cursor' => '1234',
            'uri' => 'at://did:plc:test/site.standard.document/3lab',
            'did' => 'did:plc:test',
            'collection' => 'site.standard.document',
            'rkey' => '3lab',
            'action' => 'create',
            'cid' => 'bafyreiabc',
            'rev' => '3kb3fge5lm32x',
            'live' => true,
            'createdAt' => '2026-08-04T12:00:00.000Z',
            'record' => ['$type' => 'site.standard.document', 'title' => 'Hello'],
        ], $overrides);
    }

    #[Test]    public function it_normalizes_a_create_event()
    {
        $event = (new ObeliskEventNormalizer())->normalize($this->event());

        $this->assertSame('did:plc:test', $event->did);
        $this->assertSame('commit', $event->kind);
        $this->assertTrue($event->isCommit());
        $this->assertSame('site.standard.document', $event->commit->collection);
        $this->assertSame('3lab', $event->commit->rkey);
        $this->assertSame('bafyreiabc', $event->commit->cid);
        $this->assertSame('3kb3fge5lm32x', $event->commit->rev);
        $this->assertSame(SignalCommitOperation::Create, $event->commit->operation);
        $this->assertSame('Hello', $event->commit->record->title);
        $this->assertSame('1234', $event->cursor);
        $this->assertFalse($event->backfill);
    }

    #[Test]    public function it_maps_a_delete_event_with_no_record()
    {
        $event = (new ObeliskEventNormalizer())->normalize(
            $this->event(['action' => 'delete', 'record' => null, 'cid' => null])
        );

        $this->assertTrue($event->commit->isDelete());
        $this->assertNull($event->commit->record);
        $this->assertNull($event->commit->cid);
    }

    #[Test]    public function it_marks_non_live_events_as_backfill()
    {
        $event = (new ObeliskEventNormalizer())->normalize($this->event(['live' => false]));

        $this->assertTrue($event->backfill);
    }

    #[Test]    public function it_derives_time_us_from_created_at()
    {
        $event = (new ObeliskEventNormalizer())->normalize(
            $this->event(['createdAt' => '2026-08-04T12:00:00.000Z'])
        );

        $this->assertSame(strtotime('2026-08-04T12:00:00Z') * 1_000_000, $event->timeUs);
    }

    #[Test]    public function it_falls_back_to_now_for_a_missing_or_unparsable_created_at()
    {
        $normalizer = new ObeliskEventNormalizer();

        $this->assertGreaterThan(0, $normalizer->normalize($this->event(['createdAt' => null]))->timeUs);
        $this->assertGreaterThan(0, $normalizer->normalize($this->event(['createdAt' => 'not a date']))->timeUs);
    }

    #[Test]    public function it_rejects_events_missing_required_fields()
    {
        $this->expectException(InvalidArgumentException::class);

        (new ObeliskEventNormalizer())->normalize($this->event(['did' => null]));
    }

    #[Test]    public function it_round_trips_the_cursor_through_to_array()
    {
        $event = (new ObeliskEventNormalizer())->normalize($this->event());

        $this->assertSame('1234', $event->toArray()['cursor']);
        $this->assertFalse($event->toArray()['backfill']);
    }
}
