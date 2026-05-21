<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Refund;
use App\Models\Transaction;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    /**
     * MYR minor-unit threshold above which AUTHORIZE routes to PRE_SETTLEMENT_REVIEW.
     * 100_000 = MYR 1,000.00
     */
    private const int REVIEW_THRESHOLD_MYR_MINOR = 100_000;

    public function __construct(private CurrencyService $currencyService) {}

    /**
     * Create a transaction linked to the app account.
     * Returns ['idempotent' => bool, 'transaction' => Transaction].
     *
     * @return array{idempotent: bool, transaction: Transaction}
     * @throws \RuntimeException on unsupported currency or payment_id conflict
     */
    public function create(string $paymentId, string $amountDecimal, string $currency, string $merchantId): array
    {
        if (!$this->currencyService->supports($currency)) {
            throw new \RuntimeException("Unsupported currency '$currency'");
        }

        $amountMinor = $this->toMinorUnits($amountDecimal, $currency);
        $account     = Account::ensureExists();
        $existing    = Transaction::findByPaymentId($paymentId);

        if ($existing) {
            if ($existing->amount === $amountMinor
                && $existing->currency === $currency
                && $existing->merchant_id === $merchantId) {
                return ['idempotent' => true, 'transaction' => $existing];
            }

            DB::transaction(function () use ($existing) {
                $existing->status    = TransactionStatus::Failed;
                $existing->failed_at = now();
                $existing->save();
            });

            throw new \RuntimeException("CREATE conflict - existing transaction marked FAILED");
        }

        $transaction = DB::transaction(fn () => Transaction::create([
            'payment_id'  => $paymentId,
            'account_id'  => $account->id,
            'merchant_id' => $merchantId,
            'amount'      => $amountMinor,
            'currency'    => $currency,
            'status'      => TransactionStatus::Initiated,
        ]));

        return ['idempotent' => false, 'transaction' => $transaction];
    }

    /**
     * Apply a lifecycle action to a transaction, updating its status and the
     * account balance where applicable.
     *
     * Supported $action values (case-insensitive):
     *   AUTHORIZE  – INITIATED → AUTHORIZED | PRE_SETTLEMENT_REVIEW
     *   CAPTURE    – AUTHORIZED | PRE_SETTLEMENT_REVIEW → CAPTURED
     *   VOID       – INITIATED | AUTHORIZED → VOIDED         ($data['reason'] optional)
     *   REFUND     – CAPTURED → REFUNDED, balance -=          ($data['amount'] optional decimal)
     *   SETTLE     – CAPTURED → SETTLED, balance +=           (idempotent if already SETTLED)
     *
     * @param  array{reason?: string, amount?: string}  $data
     * @return array{idempotent: bool, transaction: Transaction}
     * @throws \RuntimeException on unknown action, invalid transition, or transaction not found
     */
    public function update(string $paymentId, string $action, array $data = []): array
    {
        $transaction = Transaction::findByPaymentId($paymentId)
            ?? throw new \RuntimeException("Transaction '$paymentId' not found");

        return match (strtoupper($action)) {
            'AUTHORIZE' => $this->authorize($transaction),
            'CAPTURE'   => $this->capture($transaction),
            'VOID'      => $this->void($transaction, $data['reason'] ?? ''),
            'REFUND'    => $this->refund($transaction, $data['amount'] ?? null),
            'SETTLE'    => $this->settle($transaction),
            default     => throw new \RuntimeException("Unknown action '$action'"),
        };
    }

    // -------------------------------------------------------------------------

    /** @return array{idempotent: false, transaction: Transaction} */
    private function authorize(Transaction $transaction): array
    {
        if ($transaction->status !== TransactionStatus::Initiated) {
            throw new \RuntimeException("Invalid transition - cannot AUTHORIZE from {$transaction->status->value}");
        }

        $myrMinor  = $this->currencyService->convertMinorUnits($transaction->amount, $transaction->currency, 'MYR');
        $newStatus = $myrMinor >= self::REVIEW_THRESHOLD_MYR_MINOR
            ? TransactionStatus::PreSettlementReview
            : TransactionStatus::Authorized;

        $transaction->status        = $newStatus;
        $transaction->authorized_at = now();
        $transaction->save();

        return ['idempotent' => false, 'transaction' => $transaction];
    }

    /** @return array{idempotent: false, transaction: Transaction} */
    private function capture(Transaction $transaction): array
    {
        if (!in_array($transaction->status, [TransactionStatus::Authorized, TransactionStatus::PreSettlementReview], true)) {
            throw new \RuntimeException("Invalid transition - cannot CAPTURE from {$transaction->status->value}");
        }

        $transaction->status      = TransactionStatus::Captured;
        $transaction->captured_at = now();
        $transaction->save();

        return ['idempotent' => false, 'transaction' => $transaction];
    }

    /** @return array{idempotent: false, transaction: Transaction} */
    private function void(Transaction $transaction, string $reason): array
    {
        if (!in_array($transaction->status, [TransactionStatus::Initiated, TransactionStatus::Authorized], true)) {
            throw new \RuntimeException("Invalid transition - cannot VOID from {$transaction->status->value}");
        }

        $transaction->status      = TransactionStatus::Voided;
        $transaction->void_reason = $reason ?: null;
        $transaction->voided_at   = now();
        $transaction->save();

        return ['idempotent' => false, 'transaction' => $transaction];
    }

    /** @return array{idempotent: false, transaction: Transaction} */
    private function refund(Transaction $transaction, ?string $amountDecimal): array
    {
        if (!in_array($transaction->status, [TransactionStatus::Captured, TransactionStatus::PartiallyRefunded], true)) {
            throw new \RuntimeException("Invalid transition - cannot REFUND from {$transaction->status->value}");
        }

        $newRefundMinor = $amountDecimal !== null
            ? $this->toMinorUnits($amountDecimal, $transaction->currency)
            : $transaction->amount;

        $totalRefunded = (int) $transaction->refunds()->sum('amount');
        $remaining     = $transaction->amount - $totalRefunded;

        if ($newRefundMinor > $remaining) {
            $requestedDecimal = Money::ofMinor($newRefundMinor, $transaction->currency)->getAmount()->__toString();
            $remainingDecimal = Money::ofMinor($remaining, $transaction->currency)->getAmount()->__toString();
            throw new \RuntimeException(
                "Refund amount {$requestedDecimal} {$transaction->currency} exceeds remaining refundable amount {$remainingDecimal} {$transaction->currency}"
            );
        }

        $fullyRefunded  = ($totalRefunded + $newRefundMinor) === $transaction->amount;
        $newStatus      = $fullyRefunded ? TransactionStatus::Refunded : TransactionStatus::PartiallyRefunded;
        $myrRefundMinor = $this->currencyService->convertMinorUnits($newRefundMinor, $transaction->currency, 'MYR');

        DB::transaction(function () use ($transaction, $newRefundMinor, $newStatus, $myrRefundMinor) {
            Refund::create([
                'transaction_id' => $transaction->id,
                'amount'         => $newRefundMinor,
                'currency'       => $transaction->currency,
            ]);

            $transaction->status = $newStatus;
            $transaction->save();

            Account::ensureExists()->decrement('balance', $myrRefundMinor);
        });

        return ['idempotent' => false, 'transaction' => $transaction->fresh()];
    }

    /** @return array{idempotent: bool, transaction: Transaction} */
    private function settle(Transaction $transaction): array
    {
        if ($transaction->status === TransactionStatus::Settled) {
            return ['idempotent' => true, 'transaction' => $transaction];
        }

        if ($transaction->status !== TransactionStatus::Captured) {
            throw new \RuntimeException("Invalid transition - cannot SETTLE from {$transaction->status->value}");
        }

        $myrMinor = $this->currencyService->convertMinorUnits($transaction->amount, $transaction->currency, 'MYR');

        DB::transaction(function () use ($transaction, $myrMinor) {
            $transaction->status     = TransactionStatus::Settled;
            $transaction->settled_at = now();
            $transaction->save();

            Account::ensureExists()->increment('balance', $myrMinor);
        });

        return ['idempotent' => false, 'transaction' => $transaction->fresh()];
    }

    private function toMinorUnits(string $amountDecimal, string $currency): int
    {
        return Money::of($amountDecimal, $currency)->getMinorAmount()->toInt();
    }
}
