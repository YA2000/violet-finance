<?php

namespace Tests\Feature;

use App\Models\Project;
use Tests\TestCase;

/** Automatic project status from the dates. */
class ProjectStatusTest extends TestCase
{
    public function test_status_follows_the_dates(): void
    {
        $p = new Project(['code' => '901', 'start_date' => now()->addMonth(), 'end_date' => now()->addYear()]);
        $this->assertSame('Pipeline', $p->statusByDates());
        $p->start_date = now()->subMonth();
        $this->assertSame('Ongoing', $p->statusByDates());
        $p->end_date = now()->subDay();
        $this->assertSame('Ended', $p->statusByDates());
    }

    public function test_year_numbers_follow_on(): void
    {
        $n = Project::nextYearNo(2031);
        $this->assertSame(1, $n);
    }
}
