<?php

namespace Tests\Feature;

use App\Models\BudgetLine;
use App\Models\Document;
use App\Services\Expenses;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every final PTR line lands in spend_lines (used by the budget figures); a cancelled one leaves it. */
class SpendLinesTest extends TestCase
{
    public function test_final_ptr_lines_are_written_in_dollars(): void
    {
        $bl = BudgetLine::firstOrFail();      // any line of the seeded master data
        $doc = Document::create(['form_code' => 'ptr', 'state' => 'done', 'number' => 'T2600001', 'date' => '2026-11-01', 'currency' => 'SYP',
            'user_id' => $this->admin()->id, 'data' => ['lines' => [
                ['budget_line_id' => $bl->id, 'expected_amount' => 197884, 'home_amount' => 1622],
                ['budget_line_id' => null, 'expected_amount' => 50],
            ]]]);
        $this->assertSame(1, Expenses::sync($doc));
        $row = DB::table('spend_lines')->first();
        $this->assertEquals(1622, $row->usd);
        $this->assertSame('SYP', $row->currency);

        $doc->update(['state' => 'cancel']);
        Expenses::sync($doc);
        $this->assertSame(0, DB::table('spend_lines')->count());
    }
}
