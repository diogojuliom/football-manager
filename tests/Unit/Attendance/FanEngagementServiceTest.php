<?php

namespace Tests\Unit\Attendance;

use App\Application\Attendance\FanEngagementService;
use PHPUnit\Framework\TestCase;

class FanEngagementServiceTest extends TestCase
{
    public function test_form_and_title_stakes_adjust_engagement_by_at_most_three_points(): void
    {
        $fans = new FanEngagementService;
        $this->assertSame(2, $fans->delta(2, 1, 0, 30, 5));
        $this->assertSame(3, $fans->delta(2, 1, 3, 30, 5));
        $this->assertSame(3, $fans->delta(2, 1, 0, 80, 2));
        $this->assertSame(3, $fans->delta(2, 1, 4, 80, 1));
        $this->assertSame(0, $fans->delta(1, 1, 4, 80, 1));
        $this->assertSame(-2, $fans->delta(0, 1, 0, 30, 5));
        $this->assertSame(-3, $fans->delta(0, 1, -4, 30, 5));
    }
}
