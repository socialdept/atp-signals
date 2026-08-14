<?php

namespace SocialDept\AtpSignals\Events;

/**
 * Laravel event fired when a Jetstream v2 instance reports an OutdatedCursor
 * advisory: the requested cursor fell outside the server's lookback window, so
 * the stream was clamped and a gap exists between the stored position and
 * where events resume. Listen for this to trigger an archive backfill instead
 * of silently losing the gap.
 */
class CursorOutdated
{
    public function __construct(
        public ?int $requestedCursor = null,
        public ?string $message = null,
    ) {
    }
}
