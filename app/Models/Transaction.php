<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Services\CurrencyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $fillable = [
        'payment_id',
        'account_id',
        'merchant_id',
        'amount',
        'currency',
        'status',
        'void_reason',
        'failed_reason',
        'batch_id',
        'authorized_at',
        'captured_at',
        'settled_at',
        'voided_at',
        'failed_at',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'status'        => TransactionStatus::class,
        'authorized_at' => 'datetime',
        'captured_at'   => 'datetime',
        'settled_at'    => 'datetime',
        'voided_at'     => 'datetime',
        'failed_at'     => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public static function findByPaymentId(string $paymentId): ?self
    {
        return static::where('payment_id', $paymentId)->first();
    }

    public function getAuditLog(): array
    {
        $events = [];

        $events[] = ['event' => 'INITIATED', 'at' => $this->created_at, 'meta' => [
            'amount'      => $this->amount,
            'currency'    => $this->currency,
            'merchant_id' => $this->merchant_id,
        ]];

        if ($this->authorized_at) {
            $cs       = new CurrencyService();
            $myrMinor = $cs->convertMinorUnits($this->amount, $this->currency, 'MYR');
            $label    = $myrMinor >= 100_000 ? 'PRE_SETTLEMENT_REVIEW' : 'AUTHORIZED';
            $events[] = ['event' => $label, 'at' => $this->authorized_at, 'meta' => []];
        }

        if ($this->captured_at) {
            $events[] = ['event' => 'CAPTURED', 'at' => $this->captured_at, 'meta' => []];
        }

        if ($this->settled_at) {
            $events[] = ['event' => 'SETTLED', 'at' => $this->settled_at, 'meta' => []];
        }

        if ($this->voided_at) {
            $events[] = ['event' => 'VOIDED', 'at' => $this->voided_at, 'meta' => $this->void_reason ? ['reason' => $this->void_reason] : []];
        }

        if ($this->failed_at) {
            $events[] = ['event' => 'FAILED', 'at' => $this->failed_at, 'meta' => $this->failed_reason ? ['reason' => $this->failed_reason] : []];
        }

        $runningTotal = 0;
        $lastRefund   = null;

        foreach ($this->refunds()->orderBy('created_at')->get() as $refund) {
            $runningTotal += $refund->amount;
            $events[]      = ['event' => 'REFUND', 'at' => $refund->created_at, 'meta' => [
                'amount'             => $refund->amount,
                'currency'           => $refund->currency,
                'running_total'      => $runningTotal,
                'transaction_amount' => $this->amount,
            ]];
            $lastRefund = $refund;
        }

        usort($events, fn ($a, $b) => $a['at']->timestamp <=> $b['at']->timestamp);

        // Append synthetic REFUNDED after sort so it always follows the last REFUND
        if ($lastRefund && $runningTotal === $this->amount) {
            $events[] = ['event' => 'REFUNDED', 'at' => $lastRefund->created_at, 'meta' => []];
        }

        return $events;
    }
}
