<?php

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Services\CurrencyService;
use App\Services\TransactionService;

beforeEach(function () {
    Account::ensureExists();
    $this->service = new TransactionService(new CurrencyService());
});

// ---------------------------------------------------------------------------
// Happy-path flows
// ---------------------------------------------------------------------------

test('CREATE → AUTHORIZE → CAPTURE → SETTLE → STATUS', function () {
    $this->service->create('P001', '50.00', 'MYR', 'M01');
    $this->service->update('P001', 'AUTHORIZE');
    $this->service->update('P001', 'CAPTURE');
    $result = $this->service->update('P001', 'SETTLE');

    expect($result['idempotent'])->toBeFalse();
    expect($result['transaction']->status)->toBe(TransactionStatus::Settled);
    expect($result['transaction']->settled_at)->not->toBeNull();
});

test('account balance increments on SETTLE', function () {
    $this->service->create('P002', '10.00', 'MYR', 'M01');
    $this->service->update('P002', 'AUTHORIZE');
    $this->service->update('P002', 'CAPTURE');
    $this->service->update('P002', 'SETTLE');

    expect(Account::ensureExists()->balance)->toBe(1000);
});

test('CREATE → AUTHORIZE → VOID', function () {
    $this->service->create('P003', '20.00', 'MYR', 'M01');
    $this->service->update('P003', 'AUTHORIZE');
    $result = $this->service->update('P003', 'VOID', ['reason' => 'customer_request']);

    expect($result['transaction']->status)->toBe(TransactionStatus::Voided);
    expect($result['transaction']->void_reason)->toBe('customer_request');
});

test('VOID from INITIATED is allowed', function () {
    $this->service->create('P004', '20.00', 'MYR', 'M01');
    $result = $this->service->update('P004', 'VOID');

    expect($result['transaction']->status)->toBe(TransactionStatus::Voided);
});

test('CREATE → AUTHORIZE → CAPTURE → REFUND (full)', function () {
    $this->service->create('P005', '30.00', 'MYR', 'M01');
    $this->service->update('P005', 'AUTHORIZE');
    $this->service->update('P005', 'CAPTURE');
    $result = $this->service->update('P005', 'REFUND');

    expect($result['transaction']->status)->toBe(TransactionStatus::Refunded);
    expect($result['transaction']->refunds()->sum('amount'))->toBe(3000);
    expect($result['transaction']->refunds()->count())->toBe(1);
});

test('REFUND with partial amount', function () {
    $this->service->create('P006', '50.00', 'MYR', 'M01');
    $this->service->update('P006', 'AUTHORIZE');
    $this->service->update('P006', 'CAPTURE');
    $result = $this->service->update('P006', 'REFUND', ['amount' => '20.00']);

    expect($result['transaction']->status)->toBe(TransactionStatus::PartiallyRefunded);
    expect($result['transaction']->refunds()->sum('amount'))->toBe(2000);
    expect($result['transaction']->refunds()->count())->toBe(1);
});

test('account balance decrements on REFUND', function () {
    $this->service->create('P007', '40.00', 'MYR', 'M01');
    $this->service->update('P007', 'AUTHORIZE');
    $this->service->update('P007', 'CAPTURE');
    $this->service->update('P007', 'SETTLE');

    expect(Account::ensureExists()->balance)->toBe(4000);

    $this->service->create('P008', '40.00', 'MYR', 'M01');
    $this->service->update('P008', 'AUTHORIZE');
    $this->service->update('P008', 'CAPTURE');
    $this->service->update('P008', 'REFUND', ['amount' => '15.00']);

    expect(Account::ensureExists()->balance)->toBe(2500);
});

test('large-value AUTHORIZE routes to PRE_SETTLEMENT_REVIEW (≥ MYR 1000)', function () {
    $this->service->create('P009', '1000.00', 'MYR', 'M01');
    $result = $this->service->update('P009', 'AUTHORIZE');

    expect($result['transaction']->status)->toBe(TransactionStatus::PreSettlementReview);
});

test('PRE_SETTLEMENT_REVIEW can be captured', function () {
    $this->service->create('P010', '2000.00', 'MYR', 'M01');
    $this->service->update('P010', 'AUTHORIZE');
    $result = $this->service->update('P010', 'CAPTURE');

    expect($result['transaction']->status)->toBe(TransactionStatus::Captured);
});

test('below-threshold AUTHORIZE goes directly to AUTHORIZED', function () {
    $this->service->create('P011', '999.99', 'MYR', 'M01');
    $result = $this->service->update('P011', 'AUTHORIZE');

    expect($result['transaction']->status)->toBe(TransactionStatus::Authorized);
});

// ---------------------------------------------------------------------------
// Invalid transitions
// ---------------------------------------------------------------------------

test('REFUND before CAPTURE throws', function () {
    $this->service->create('P020', '10.00', 'MYR', 'M01');
    $this->service->update('P020', 'AUTHORIZE');

    expect(fn () => $this->service->update('P020', 'REFUND'))
        ->toThrow(\RuntimeException::class, 'Invalid transition');
});

test('CAPTURE before AUTHORIZE throws', function () {
    $this->service->create('P021', '10.00', 'MYR', 'M01');

    expect(fn () => $this->service->update('P021', 'CAPTURE'))
        ->toThrow(\RuntimeException::class, 'Invalid transition');
});

test('VOID after CAPTURE throws', function () {
    $this->service->create('P022', '10.00', 'MYR', 'M01');
    $this->service->update('P022', 'AUTHORIZE');
    $this->service->update('P022', 'CAPTURE');

    expect(fn () => $this->service->update('P022', 'VOID'))
        ->toThrow(\RuntimeException::class, 'Invalid transition');
});

test('AUTHORIZE on non-INITIATED throws', function () {
    $this->service->create('P023', '10.00', 'MYR', 'M01');
    $this->service->update('P023', 'AUTHORIZE');

    expect(fn () => $this->service->update('P023', 'AUTHORIZE'))
        ->toThrow(\RuntimeException::class, 'Invalid transition');
});

test('SETTLE on non-CAPTURED throws', function () {
    $this->service->create('P024', '10.00', 'MYR', 'M01');
    $this->service->update('P024', 'AUTHORIZE');

    expect(fn () => $this->service->update('P024', 'SETTLE'))
        ->toThrow(\RuntimeException::class, 'Invalid transition');
});

test('REFUND amount exceeding original throws', function () {
    $this->service->create('P025', '10.00', 'MYR', 'M01');
    $this->service->update('P025', 'AUTHORIZE');
    $this->service->update('P025', 'CAPTURE');

    expect(fn () => $this->service->update('P025', 'REFUND', ['amount' => '11.00']))
        ->toThrow(\RuntimeException::class, 'exceeds remaining refundable');
});

test('unknown action throws', function () {
    $this->service->create('P026', '10.00', 'MYR', 'M01');

    expect(fn () => $this->service->update('P026', 'CHARGEBACK'))
        ->toThrow(\RuntimeException::class, 'Unknown action');
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

test('repeated CREATE with identical attributes is idempotent', function () {
    $r1 = $this->service->create('P030', '25.00', 'MYR', 'M01');
    $r2 = $this->service->create('P030', '25.00', 'MYR', 'M01');

    expect($r1['idempotent'])->toBeFalse();
    expect($r2['idempotent'])->toBeTrue();
    expect(\App\Models\Transaction::where('payment_id', 'P030')->count())->toBe(1);
});

test('CREATE conflict marks existing as FAILED and throws', function () {
    $this->service->create('P031', '25.00', 'MYR', 'M01');

    expect(fn () => $this->service->create('P031', '99.00', 'MYR', 'M01'))
        ->toThrow(\RuntimeException::class, 'CREATE conflict');

    $tx = \App\Models\Transaction::findByPaymentId('P031');
    expect($tx->status)->toBe(TransactionStatus::Failed);
});

test('repeated SETTLE on already-settled payment is idempotent', function () {
    $this->service->create('P032', '10.00', 'MYR', 'M01');
    $this->service->update('P032', 'AUTHORIZE');
    $this->service->update('P032', 'CAPTURE');
    $this->service->update('P032', 'SETTLE');

    $result = $this->service->update('P032', 'SETTLE');
    expect($result['idempotent'])->toBeTrue();
    expect(Account::ensureExists()->balance)->toBe(1000);
});

test('operation on non-existent payment_id throws', function () {
    expect(fn () => $this->service->update('GHOST', 'AUTHORIZE'))
        ->toThrow(\RuntimeException::class, 'not found');
});

test('unsupported currency throws on CREATE', function () {
    expect(fn () => $this->service->create('P040', '10.00', 'XYZ', 'M01'))
        ->toThrow(\RuntimeException::class, "Unsupported currency 'XYZ'");
});

// ---------------------------------------------------------------------------
// Partial refunds
// ---------------------------------------------------------------------------

test('partial REFUND from CAPTURED sets PARTIALLY_REFUNDED', function () {
    $this->service->create('P050', '100.00', 'MYR', 'M01');
    $this->service->update('P050', 'AUTHORIZE');
    $this->service->update('P050', 'CAPTURE');
    $result = $this->service->update('P050', 'REFUND', ['amount' => '60.00']);

    expect($result['transaction']->status)->toBe(TransactionStatus::PartiallyRefunded);
    expect($result['transaction']->refunds()->count())->toBe(1);
    expect($result['transaction']->refunds()->sum('amount'))->toBe(6000);
});

test('second partial REFUND from PARTIALLY_REFUNDED completes to REFUNDED', function () {
    $this->service->create('P051', '100.00', 'MYR', 'M01');
    $this->service->update('P051', 'AUTHORIZE');
    $this->service->update('P051', 'CAPTURE');
    $this->service->update('P051', 'REFUND', ['amount' => '60.00']);
    $result = $this->service->update('P051', 'REFUND', ['amount' => '40.00']);

    expect($result['transaction']->status)->toBe(TransactionStatus::Refunded);
    expect($result['transaction']->refunds()->count())->toBe(2);
    expect($result['transaction']->refunds()->sum('amount'))->toBe(10000);
});

test('REFUND exceeding remaining after partial refund throws', function () {
    $this->service->create('P052', '100.00', 'MYR', 'M01');
    $this->service->update('P052', 'AUTHORIZE');
    $this->service->update('P052', 'CAPTURE');
    $this->service->update('P052', 'REFUND', ['amount' => '60.00']);

    expect(fn () => $this->service->update('P052', 'REFUND', ['amount' => '60.00']))
        ->toThrow(\RuntimeException::class, 'exceeds remaining refundable');
});

test('REFUND from REFUNDED status throws', function () {
    $this->service->create('P053', '50.00', 'MYR', 'M01');
    $this->service->update('P053', 'AUTHORIZE');
    $this->service->update('P053', 'CAPTURE');
    $this->service->update('P053', 'REFUND');

    expect(fn () => $this->service->update('P053', 'REFUND'))
        ->toThrow(\RuntimeException::class, 'Invalid transition');
});
