<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TxnCounter extends Model
{
    protected $table = 'txn_counters';
    protected $guarded = ['id'];
}
