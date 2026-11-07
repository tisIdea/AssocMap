<?php

namespace Tests\Fixtures {
    final class MemberApplicationReadClock
    {
        public static int $now = 0;
    }
}

namespace App\Services\Members {
    // Loaded only by the isolated read-deadline test process.
    function hrtime(bool $asNumber = false): array|int|float
    {
        return $asNumber ? \Tests\Fixtures\MemberApplicationReadClock::$now : \hrtime();
    }
}
