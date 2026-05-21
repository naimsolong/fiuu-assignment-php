# Payment Pipeline CLI

A command-line application that simulates a payment processing pipeline — supporting creation, authorisation, capture, settlement, voiding, and refunds across 28 currencies.

## Development

### Requirements

- PHP 8.4+
- Composer

### Installation

```bash
composer install
```

The application is self-bootstrapping: on first run it creates the SQLite database at `~/.payment-cli/database.sqlite` and runs all migrations automatically. No manual setup is needed.

### Running

**Interactive shell:**
```bash
php payment-cli payment
php payment-cli account
```

**Building a standalone binary (PHAR):**
```bash
php payment-cli app:build payment-cli
./builds/payment-cli payment
./builds/payment-cli account
```

### Project structure

```
app/
  Commands/
    Concerns/Shell.php        # Abstract shell: input loop, tokeniser, comment stripping
    Payment.php               # Concrete command: dispatches tokens to service calls
    Account.php               # Concrete command: account inspection and reset
  Enums/
    TransactionStatus.php     # Backed string enum for all valid statuses
  Models/
    Account.php               # Single-row balance holder
    Transaction.php           # One row per payment
  Services/
    CurrencyService.php       # brick/money wrapper; 28 BNM rates; cross-rate via MYR
    TransactionService.php    # Domain layer: create(), update(), all state transitions
database/migrations/          # Accounts and transactions tables
tests/
  Feature/
    CommandParserTest.php     # Shell tokeniser, comment rules, alias, missing-arg errors
    TransactionServiceTest.php# Happy paths, invalid transitions, idempotency, currencies
```

### Running tests

```bash
./vendor/bin/pest
```

Tests use an in-memory SQLite database (configured in `phpunit.xml.dist`) and refresh it between test runs via `RefreshDatabase`.

### Code style

```bash
./vendor/bin/pint
```

[Laravel Pint](https://laravel.com/docs/pint) is configured at its default (Laravel preset).

---

## Commands

### Account

**Start interactive shell:**
```bash
// Development
php payment-cli account

// Binary file
./payment-cli account
```

**Available commands:**

```
INFO
RESET
HELP
EXIT / QUIT
```

| Command | Description |
|---------|-------------|
| `INFO` | Display account details (ID, balance, creation date) and a list of all transactions |
| `RESET` | Zero the account balance and permanently delete all transaction records |
| `HELP` | Show available commands |
| `EXIT` / `QUIT` | Exit the shell |

---

### Payment

**Start interactive Shell:**
```bash
// Development
php payment-cli payment

// Binary file
./payment-cli payment
```

**Available commands:**

```
CREATE <payment_id> <amount> <currency> <merchant_id>
AUTHORIZE <payment_id>
AUTH <payment_id>           (alias for AUTHORIZE)
CAPTURE <payment_id>
VOID <payment_id> [reason_code]
REFUND <payment_id> [amount]
SETTLE <payment_id>
SETTLEMENT <batch_id>
STATUS <payment_id>
LIST
AUDIT <payment_id>
HELP
CLEAR
EXIT / QUIT
```

Commands are case-insensitive. Lines may contain inline comments starting with `#`, but only when the `#` appears as a standalone token at position 5 or later (i.e. after the command and three argument tokens). In all other positions `#` is treated as a normal character and results in an unknown command error.

### Comment parsing examples

| Input | Behaviour |
|-------|-----------|
| `CREATE P1001 10.00 MYR M01 # test` | Valid — comment stripped |
| `AUTHORIZE P1001 # retry` | Valid — comment stripped |
| `# CREATE P1002 11.00 MYR M01` | **Error** — `#` is the command token |
| `CREATE P1003 10.00 # MYR M01` | **Error** — `#` is at position 4, treated as currency |

---

## Design

### Architecture

The application follows a three-layer separation:

- **Shell** (`app/Commands/Concerns/Shell.php`) — parses input lines into tokens and maps them to command handlers. It owns no business logic; each `cmd*` method delegates entirely to a service.
- **TransactionService** (`app/Services/TransactionService.php`) — the domain layer. Two public methods: `create` and `update`. All state transitions, balance updates, and idempotency rules live here.
- **CurrencyService** (`app/Services/CurrencyService.php`) — wraps `brick/money` with 28 BNM mid-market rates (as of 2026-05-21). Handles minor-unit conversions and cross-rate routing through MYR.

The state machine is enforced at the service layer using explicit `match`/`in_array` guards rather than a formal state machine library, which keeps it readable and easy to extend with a new state or transition.

### Data Storage

SQLite via Laravel Eloquent. Two tables:

- `accounts` — one row per application instance; holds the running `balance` in MYR minor units.
- `transactions` — one row per payment; stores `amount` in the original currency's minor units alongside the ISO `currency` code.

Amounts are stored as `BIGINT UNSIGNED` minor units throughout (e.g. MYR 10.00 → `1000`). Decimal conversion is handled at the application layer using `brick/money` based on each currency's exponent.

### Configuration

| Variable | Default | Purpose |
|----------|---------|---------|
| `DB_DATABASE` | `~/.payment-cli/database.sqlite` | SQLite file path |
| `DB_CONNECTION` | `sqlite` | Database driver |

The `PRE_SETTLEMENT_REVIEW` threshold is defined as a named constant in `TransactionService`:

```php
private const int REVIEW_THRESHOLD_MYR_MINOR = 100_000;  // MYR 1,000.00
```

To change the threshold, update this constant (or expose it via an environment variable).

---

## Currency Support

28 currencies are supported, all sourced from the BNM Interbank Foreign Exchange Market mid-market rates as of 2026-05-21.

**Supported ISO codes:** AED, AUD, BND, CAD, CHF, CNY, EGP, EUR, GBP, HKD, IDR, INR, JPY, KHR, KRW, MMK, MYR, NPR, NZD, PHP, PKR, SAR, SGD, THB, TWD, USD, VND, ZAR

Cross-rate conversions route through MYR (e.g. USD → SGD goes USD → MYR → SGD). Attempting to use an unsupported currency at `CREATE` time returns an error.

---

## Production Differences

- **Live exchange rates** — hardcoded BNM mid-market rates would be replaced by a real-time feed (e.g. OpenExchangeRates, BNM API). Rates would be cached with a TTL and refreshed asynchronously to avoid blocking transactions on network latency.
- **Persistent batch tracking** — `SETTLEMENT` would store a `batch_id` on each transaction row and produce proper settlement reports scoped to a settlement window rather than scanning all settled transactions.
- **Event sourcing / audit log** — instead of overwriting `status` in place, each state change would append an immutable event record (payment_id, from_status, to_status, actor, reason, timestamp). This gives a full audit trail without needing a separate `AUDIT` command.
- **Concurrency control** — the current SQLite + `DB::transaction` approach is sufficient for single-process CLI use, but in a multi-process or distributed environment, optimistic locking (version column) or database-level row locking would be needed to prevent double-capture or double-settle.
- **Configurable thresholds via environment** — `PRE_SETTLEMENT_REVIEW` threshold and other business rules would be driven by `.env` / config rather than constants.
- **Structured error codes** — error responses would include machine-readable codes (e.g. `INVALID_TRANSITION`, `DUPLICATE_PAYMENT_ID`) rather than free-form strings, to support downstream automation.
- **Merchant validation** — `merchant_id` is stored as-is here; in production it would be validated against a merchants table.
