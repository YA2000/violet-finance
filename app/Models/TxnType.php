<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Transaction type of a number, with what the reservation must contain (managed on Transaction numbers → Types). */
class TxnType extends Model
{
    protected $table = 'txn_types';
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean', 'need_project' => 'boolean', 'need_cost_center' => 'boolean'];

    public const PR = ['off' => 'Not asked', 'optional' => 'Optional', 'required' => 'Required'];

    /** For the reservation dialogs: id => rules. */
    public static function rulesMap(): array
    {
        return static::orderBy('sequence')->get()->mapWithKeys(fn ($t) => [$t->id => [
            'project' => (bool) $t->need_project, 'cc' => (bool) $t->need_cost_center, 'pr' => $t->pr_field ?: 'off',
        ]])->all();
    }
}
