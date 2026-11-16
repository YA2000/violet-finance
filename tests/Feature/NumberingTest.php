<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\TxnCounter;
use Tests\TestCase;

/** Transaction numbers: T + year + 5 digits, one after the other, a deleted number is never given again. */
class NumberingTest extends TestCase
{
    public function test_starts_at_one_and_follows_on(): void
    {
        $this->assertSame('T2600001', Reservation::takeNumber(2026));
        $this->assertSame('T2600002', Reservation::takeNumber(2026));
        $this->assertSame('T2700001', Reservation::takeNumber(2027));
    }

    public function test_a_deleted_number_is_not_reused(): void
    {
        $type = \App\Models\TxnType::firstOrFail();
        $r = Reservation::reserve(['date' => '2026-03-01', 'txn_type_id' => $type->id, 'description' => 'Test'], $this->admin());
        $this->assertSame('T2600001', $r->number);
        $r->delete();
        $this->assertSame('T2600002', Reservation::takeNumber(2026));
    }

    public function test_no_cap_after_99999(): void
    {
        Reservation::takeNumber(2026);
        TxnCounter::where('year', 2026)->update(['next_number' => 100000]);
        $this->assertSame('T26100000', Reservation::takeNumber(2026));
    }

    public function test_received_payment_numbers_count_past_9999(): void
    {
        $this->assertSame('RP-26-0001', \App\Support\Numbering::next('RP', \App\Models\Receipt::class, 2026));
        \Illuminate\Support\Facades\DB::table('receipts')->insert([
            ['number' => 'RP-26-9999', 'date' => '2026-01-01', 'amount' => 1, 'currency' => 'USD', 'status' => 'recorded'],
            ['number' => 'RP-26-10000', 'date' => '2026-01-02', 'amount' => 1, 'currency' => 'USD', 'status' => 'recorded'],
        ]);
        $this->assertSame('RP-26-10001', \App\Support\Numbering::next('RP', \App\Models\Receipt::class, 2026));
    }
}
