<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The audit trail cannot be edited through the system, and a change made directly in the database is detected. */
class AuditTest extends TestCase
{
    public function test_chain_is_intact_then_detects_tampering(): void
    {
        $this->actingAs($this->admin());
        Audit::log('money', null, 'first', ['a' => 1], ['a' => 2]);
        Audit::log('money', null, 'second');
        Audit::log('delete', null, 'third');
        $this->assertNull(Audit::verify());

        $second = AuditLog::orderBy('id')->skip(1)->first();
        DB::table('audit_logs')->where('id', $second->id)->update(['summary' => 'changed']);
        $this->assertSame($second->id, Audit::verify());
    }

    public function test_rows_cannot_be_changed_or_deleted_through_the_model(): void
    {
        Audit::log('money', null, 'first');
        $row = AuditLog::first();
        $row->summary = 'changed';
        $this->assertFalse($row->save());
        $this->assertFalse($row->delete());
        $this->assertSame('first', AuditLog::first()->summary);
    }
}
