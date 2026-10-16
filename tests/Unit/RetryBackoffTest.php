<?php

namespace Tests\Unit;

use App\Support\RetryBackoff;
use PHPUnit\Framework\TestCase;

class RetryBackoffTest extends TestCase
{
    public function test_the_wait_doubles_and_stops_at_one_day(): void
    {
        $minutes = array_map(RetryBackoff::minutes(...), range(1, 13));

        $this->assertSame([1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024, 1440, 1440], $minutes);
        $this->assertSame(1440, RetryBackoff::minutes(1000));
    }
}
