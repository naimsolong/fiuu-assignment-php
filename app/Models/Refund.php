<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = ['transaction_id', 'amount', 'currency'];

    protected $casts = ['amount' => 'integer'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
