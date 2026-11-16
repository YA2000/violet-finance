<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A PTR from saving to cancelling, through the real pages (as a user clicks), with what each step must leave behind. */
class DocumentFlowTest extends TestCase
{
    private function savePtr(array $over = []): Document
    {
        $res = $this->reserve();
        $this->actingAs($this->admin())->post('/forms/ptr', ['payload' => $this->ptrPayload($over), 'reservation_id' => $res->id])->assertRedirect()->assertSessionHasNoErrors();
        return Document::latest('id')->firstOrFail();
    }

    public function test_saving_a_ptr_makes_it_final_numbered_and_counted_on_the_budget_line(): void
    {
        $doc = $this->savePtr();
        $this->assertSame('done', $doc->state);
        $this->assertMatchesRegularExpression('/^T26\d{5}$/', $doc->number);
        $this->assertEqualsWithDelta(197884, $doc->amount, 0.01);
        $this->assertSame('SYP', $doc->currency);
        $line = DB::table('spend_lines')->where('document_id', $doc->id)->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(1622, $line->usd, 0.01);
        $this->assertDatabaseHas('reservations', ['number' => $doc->number, 'state' => 'used']);
        $this->get('/documents/' . $doc->id)->assertOk()->assertSee($doc->number);
    }

    public function test_an_invalid_ptr_is_refused_and_nothing_is_saved(): void
    {
        $res = $this->reserve();
        $this->actingAs($this->admin())->post('/forms/ptr', ['payload' => $this->ptrPayload(['description' => '', 'lines' => []]), 'reservation_id' => $res->id])->assertSessionHasErrors();
        $this->assertSame(0, Document::count());
    }

    public function test_treasury_changes_the_value_to_pay_and_it_is_audited(): void
    {
        $doc = $this->savePtr();
        $this->post('/documents/' . $doc->id . '/treasury', ['total' => 1622 * 125, 'reason' => 'Rate of the day'])->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertEqualsWithDelta(202750, $doc->amount, 0.01);
        $this->assertEqualsWithDelta(202750, DB::table('spend_lines')->where('document_id', $doc->id)->sum('amount'), 0.01);
        $this->assertSame(1, AuditLog::where('action', 'money')->count());
    }

    public function test_unlock_change_and_lock_again(): void
    {
        $doc = $this->savePtr();
        $this->put('/documents/' . $doc->id, ['payload' => $this->ptrPayload(['description' => 'Changed'])])->assertForbidden();
        $this->post('/documents/' . $doc->id . '/unlock')->assertRedirect();
        $this->assertTrue($doc->fresh()->edit_open);
        $this->assertSame(1, AuditLog::where('action', 'unlock')->count());
        $this->put('/documents/' . $doc->id, ['payload' => $this->ptrPayload(['description' => 'Changed'])])->assertRedirect();
        $doc->refresh();
        $this->assertFalse($doc->edit_open);
        $this->assertSame('done', $doc->state);
        $this->assertSame('Changed', $doc->data['description']);
    }

    public function test_cancel_takes_the_spending_off_the_budget_line(): void
    {
        $doc = $this->savePtr();
        $this->post('/documents/' . $doc->id . '/cancel')->assertRedirect();
        $this->assertSame('cancel', $doc->fresh()->state);
        $this->assertSame(0, DB::table('spend_lines')->where('document_id', $doc->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'cancel')->count());
    }

    public function test_a_ptr_without_a_number_is_refused(): void
    {
        $this->actingAs($this->admin())->post('/forms/ptr', ['payload' => $this->ptrPayload()])->assertSessionHasErrors();
        $this->assertSame(0, Document::where('state', 'done')->count());
    }

    public function test_numbers_follow_on_across_forms(): void
    {
        $a = $this->savePtr();
        $b = $this->savePtr();
        $this->assertSame((int) substr($a->number, 3) + 1, (int) substr($b->number, 3));
    }

    public function test_print_gives_a_pdf_when_chrome_is_installed(): void
    {
        if (!\App\Support\Print\Pdf::chrome() || !trim((string) shell_exec('command -v qpdf pdfunite gs 2>/dev/null'))) {
            $this->markTestSkipped('No Chrome or no PDF merge tool (qpdf) on this machine.');
        }
        $doc = $this->savePtr();
        $r = $this->get('/documents/' . $doc->id . '/print');
        $r->assertOk();
        $this->assertStringStartsWith('%PDF', $r->getContent() ?: $r->streamedContent());
    }

    public function test_quickbooks_uploader_carries_the_number(): void
    {
        $doc = $this->savePtr();
        $r = $this->get('/documents/' . $doc->id . '/uploader');
        $r->assertOk();
        $file = $r->getFile()->getPathname();
        $rows = \App\Services\QbReconcile::readFile($file, 'x.xlsx');
        $ref = array_search('RefNumber', $rows[0], true);
        $this->assertSame($doc->number, $rows[1][$ref]);
    }
}
