<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'payment_id',
        'account_id',
        'merchant_id',
        'amount',
        'currency',
        'status',
        'refund_amount',
        'void_reason',
        'failed_reason',
        'batch_id',
        'authorized_at',
        'captured_at',
        'settled_at',
        'voided_at',
        'refunded_at',
        'failed_at',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'refund_amount' => 'integer',
        'status'        => TransactionStatus::class,
        'authorized_at' => 'datetime',
        'captured_at'   => 'datetime',
        'settled_at'    => 'datetime',
        'voided_at'     => 'datetime',
        'refunded_at'   => 'datetime',
        'failed_at'     => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public static function findByPaymentId(string $paymentId): ?self
    {
        return static::where('payment_id', $paymentId)->first();
    }
}
