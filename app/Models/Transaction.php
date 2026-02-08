<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'before_balance',
        'after_balance',
        'status',
        'reference_id',
        'description'
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
