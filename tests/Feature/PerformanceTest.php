<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Volume test: about three years of work (400 projects, 6,000 final forms, 12,000 expense lines) and the time of the
 * busiest pages. Not in the normal run (slow to prepare): tests/run.sh --group performance
 * The limits are generous on purpose; the printed times are the real result to compare from one version to the next.
 */
#[Group('performance')]
class PerformanceTest extends TestCase
{
    private function fill(): void
    {
        $uid = $this->admin()->id;
        $cur = $this->cur('USD')->id;
        $now = now();
        $p = [];
        for ($i = 1; $i <= 400; $i++) {
            $start = now()->subDays(mt_rand(0, 1000));
            $p[] = ['code' => (string) (1000 + $i), 'name' => '2026-' . sprintf('%03d', $i) . '-P' . $i, 'currency_id' => $cur, 'budget_total' => mt_rand(10, 900) * 1000,
                'start_date' => $start, 'end_date' => (clone $start)->addDays(mt_rand(90, 900)), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($p, 200) as $c) {
            DB::table('projects')->insert($c);
        }
        $lines = \App\Models\BudgetLine::pluck('id')->all();
        for ($b = 0; $b < 30; $b++) {
            $docs = [];
            for ($i = 0; $i < 200; $i++) {
                $n = $b * 200 + $i + 1;
                $docs[] = ['form_code' => 'ptr', 'state' => 'done', 'number' => sprintf('T26%05d', $n), 'date' => now()->subDays($n % 900)->toDateString(),
                    'currency' => 'USD', 'amount' => 1000, 'user_id' => $uid, 'data' => '{}', 'description' => 'Volume ' . $n, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('documents')->insert($docs);
        }
        $sl = [];
        foreach (DB::table('documents')->pluck('id') as $id) {
            for ($k = 1; $k <= 2; $k++) {
                $sl[] = ['document_id' => $id, 'form_code' => 'ptr', 'line_no' => $k, 'budget_line_id' => $lines[mt_rand(0, count($lines) - 1)], 'amount' => 500, 'currency' => 'USD', 'usd' => 500, 'date' => '2026-01-01'];
            }
            if (count($sl) >= 1000) {
                DB::table('spend_lines')->insert($sl);
                $sl = [];
            }
        }
        DB::table('spend_lines')->insert($sl);
    }

    public function test_busy_pages_stay_fast(): void
    {
        $this->fill();
        $limits = ['/' => 3.0, '/projects' => 3.0, '/forms/ptr' => 2.0, '/numbers' => 2.0, '/reports/budget-lines' => 5.0, '/reports/documents' => 3.0];
        $this->actingAs($this->admin());
        $this->get('/');                              // first view of the day refreshes the project statuses
        foreach ($limits as $url => $max) {
            $t = microtime(true);
            $this->get($url)->assertOk();
            $s = microtime(true) - $t;
            fwrite(STDERR, sprintf("  %-24s %.2fs (limit %.1fs)\n", $url, $s, $max));
            $this->assertLessThan($max, $s, "$url took " . round($s, 2) . 's');
        }
    }
}
