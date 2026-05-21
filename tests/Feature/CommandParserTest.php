<?php

use App\Commands\Payment;
use App\Models\Account;
use App\Services\CurrencyService;
use App\Services\TransactionService;

beforeEach(function () {
    Account::ensureExists();

    $this->shell = app(Payment::class);

    $this->parse = function (string $line) {
        $method = new ReflectionMethod($this->shell, 'processCommand');
        $method->setAccessible(true);
        return $method->invoke($this->shell, $line);
    };
});

// ---------------------------------------------------------------------------
// Inline comment stripping
// ---------------------------------------------------------------------------

test('inline comment after 4th token is stripped', function () {
    $result = ($this->parse)('CREATE P1001 10.00 MYR M01 # test payment');
    expect($result)->toStartWith('OK:');
});

test('inline comment after 4th token does not affect CREATE outcome', function () {
    $result = ($this->parse)('CREATE P1002 25.00 MYR M02 # another payment');
    expect($result)->toBe('OK: Created P1002 (25.00 MYR) state=INITIATED');
});

test('# at start of line is treated as unknown command', function () {
    $result = ($this->parse)('# CREATE P1001 10.00 MYR M01');
    expect($result)->toStartWith('ERROR: Unknown command');
});

test('# as first token is unknown command not a comment', function () {
    $result = ($this->parse)('# this is a comment');
    expect($result)->toStartWith('ERROR: Unknown command');
});

// ---------------------------------------------------------------------------
// Token parsing edge cases
// ---------------------------------------------------------------------------

test('empty line returns empty string', function () {
    $result = ($this->parse)('');
    expect($result)->toBe('');
});

test('whitespace-only line returns empty string', function () {
    $result = ($this->parse)('   ');
    expect($result)->toBe('');
});

test('commands are case-insensitive', function () {
    $result = ($this->parse)('create P1010 10.00 MYR M01');
    expect($result)->toStartWith('OK:');
});

test('mixed-case command works', function () {
    $result = ($this->parse)('Create P1011 10.00 MYR M01');
    expect($result)->toStartWith('OK:');
});

test('AUTHORIZE with extra tokens only uses payment_id', function () {
    ($this->parse)('CREATE P1020 10.00 MYR M01');
    $result = ($this->parse)('AUTHORIZE P1020 extra_token another_token');
    expect($result)->toStartWith('OK:');
});

test('STATUS with trailing tokens only uses payment_id', function () {
    ($this->parse)('CREATE P1030 10.00 MYR M01');
    $result = ($this->parse)('STATUS P1030 ignored_token');
    expect($result)->toStartWith('P1030');
});

// ---------------------------------------------------------------------------
// Missing argument errors
// ---------------------------------------------------------------------------

test('CREATE without arguments returns error', function () {
    $result = ($this->parse)('CREATE');
    expect($result)->toStartWith('ERROR:');
});

test('AUTHORIZE without payment_id returns error', function () {
    $result = ($this->parse)('AUTHORIZE');
    expect($result)->toStartWith('ERROR:');
});

test('SETTLE without payment_id returns error', function () {
    $result = ($this->parse)('SETTLE');
    expect($result)->toStartWith('ERROR:');
});

test('STATUS without payment_id returns error', function () {
    $result = ($this->parse)('STATUS');
    expect($result)->toStartWith('ERROR:');
});

test('VOID without payment_id returns error', function () {
    $result = ($this->parse)('VOID');
    expect($result)->toStartWith('ERROR:');
});

test('REFUND without payment_id returns error', function () {
    $result = ($this->parse)('REFUND');
    expect($result)->toStartWith('ERROR:');
});

test('SETTLEMENT without batch_id returns error', function () {
    $result = ($this->parse)('SETTLEMENT');
    expect($result)->toStartWith('ERROR:');
});

// ---------------------------------------------------------------------------
// Unknown command
// ---------------------------------------------------------------------------

test('unknown command returns error', function () {
    $result = ($this->parse)('CHARGEBACK P001');
    expect($result)->toStartWith('ERROR: Unknown command');
});

test('AUTH alias works for AUTHORIZE', function () {
    ($this->parse)('CREATE P1040 10.00 MYR M01');
    $result = ($this->parse)('AUTH P1040');
    expect($result)->toStartWith('OK:');
});

// ---------------------------------------------------------------------------
// Amount validation
// ---------------------------------------------------------------------------

test('CREATE with non-numeric amount returns error', function () {
    $result = ($this->parse)('CREATE P1050 abc MYR M01');
    expect($result)->toStartWith('ERROR: Invalid amount');
});

test('CREATE with invalid currency format returns error', function () {
    $result = ($this->parse)('CREATE P1051 10.00 MY M01');
    expect($result)->toStartWith('ERROR: Invalid currency');
});
