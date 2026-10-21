<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TxnType extends Model
{
    protected $table = 'txn_types';
    protected $guarded = ['id'];
}
