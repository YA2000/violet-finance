<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvanceExpense extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'invoice_date' => 'date', 'amount' => 'float'];

    public const STATES = ['draft' => 'Not sent', 'submitted' => 'Sent to finance', 'in_jar' => 'In JAR', 'settled' => 'Cleared', 'returned' => 'Returned'];

    public function holder() { return $this->belongsTo(AdvanceHolder::class, 'holder_id'); }
    public function advance() { return $this->belongsTo(Advance::class); }
    public function settlement() { return $this->belongsTo(AdvanceSettlement::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function budgetLine() { return $this->belongsTo(BudgetLine::class); }
    public function attachments() { return $this->hasMany(Attachment::class, 'expense_id'); }

    public function supplierName(): string
    {
        return $this->supplier ? $this->supplier->code . ' - ' . $this->supplier->name : (string) $this->supplier_text;
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }
}
