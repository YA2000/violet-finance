<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListItem extends Model
{
    protected $table = 'list_items';
    protected $guarded = ['id'];

    public const CATEGORIES = [
        'attachment' => 'Attachment Type', 'document' => 'Document Type', 'exsource' => 'Exchange Rate Source',
        'received_method' => 'Received Method', 'bank_txn_type' => 'Bank Transaction Type',
        'trust_payment_type' => 'Trust Payment Type', 'program' => 'Program', 'location' => 'Location',
        'transfer_type' => 'Transfer Type', 'rec_txn_type' => 'Reconciliation Transaction Type',
        'account_category' => 'Account Category', 'account_subcategory' => 'Account Subcategory',
        'payment_source' => 'Payment Source (Fatura)',
    ];
}
