<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Receipt;
use Tests\TestCase;

/** Money in: the treasury informs, reporting assigns the project, finance records it with a number. */
class ReceiptFlowTest extends TestCase
{
    private function inform(array $over = []): Receipt
    {
        $this->actingAs($this->admin())->post('/receipts', array_replace(['date' => '2026-11-05', 'bank_account_id' => \App\Models\Account::value('id'),
            'no_ref' => 1, 'amount' => 10000, 'currency' => 'EUR', 'payer' => 'Donor'], $over))->assertSessionHasNoErrors();
        return Receipt::latest('id')->firstOrFail();
    }

    public function test_numbers_and_the_three_steps(): void
    {
        $r = $this->inform();
        $this->assertMatchesRegularExpression('/^RP-26-\d{4}$/', $r->number);
        $this->assertSame('project', $r->status);

        $p = Project::orderBy('id')->firstOrFail();
        $p->update(['currency_id' => $this->cur('USD')->id]);
        $this->post('/receipts/' . $r->id . '/project', ['project_id' => $p->id, 'project_amount' => 11600,
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('request.pdf', 10, 'application/pdf')]])->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame('record', $r->status);
        $this->assertEqualsWithDelta(11600, $r->project_amount, 0.01);

        $res = $this->reserve();
        $this->post('/receipts/' . $r->id . '/record', ['reservation_id' => $res->id, 'record_description' => 'Recorded in QuickBooks',
            'bank_account_id' => $r->bank_account_id, 'project_id' => $p->id])->assertSessionHasNoErrors();
        $this->assertSame('recorded', $r->fresh()->status);
        $this->assertDatabaseHas('reservations', ['id' => $res->id, 'state' => 'used']);
    }

    public function test_same_currency_as_the_project_goes_straight_to_finance(): void
    {
        $p = Project::orderBy('id')->firstOrFail();
        $p->update(['currency_id' => $this->cur('USD')->id]);
        $r = $this->inform(['currency' => 'USD', 'project_id' => $p->id]);
        $this->assertSame('record', $r->status);
        $this->assertEqualsWithDelta(10000, $r->project_amount, 0.01);
    }

    public function test_a_dekont_number_is_required_unless_ticked(): void
    {
        $this->actingAs($this->admin())->post('/receipts', ['date' => '2026-11-05', 'bank_account_id' => \App\Models\Account::value('id'),
            'amount' => 100, 'currency' => 'USD'])->assertSessionHasErrors('bank_ref');
        $this->assertSame(0, Receipt::count());
    }

    public function test_deleting_a_payment_is_audited(): void
    {
        $r = $this->inform();
        $this->delete('/receipts/' . $r->id)->assertRedirect();
        $this->assertSame(0, Receipt::count());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'delete')->count());
    }
}
