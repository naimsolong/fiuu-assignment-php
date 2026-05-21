<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **fiuu-assignment-php** (39 symbols, 37 relationships, 0 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/fiuu-assignment-php/context` | Codebase overview, check index freshness |
| `gitnexus://repo/fiuu-assignment-php/clusters` | All functional areas |
| `gitnexus://repo/fiuu-assignment-php/processes` | All execution flows |
| `gitnexus://repo/fiuu-assignment-php/process/{name}` | Step-by-step execution trace |

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->

---

# Domain & Database Design

## Schema

### `accounts`
- Single account for the entire application instance — no `merchant_id` on this table
- Created automatically on shell startup if none exists
- `balance` defaults to `0.00` and is updated as transactions settle/refund

```sql
accounts: id, balance, created_at, updated_at
```

### `transactions`
- Each `CREATE` command produces one transaction record linked to `account_id`
- `merchant_id` lives here, not on `accounts`
- Each transaction tracks its own `amount` independently of the account balance

```sql
transactions: id, payment_id, account_id, merchant_id, amount, currency, status,
              refund_amount, void_reason, failed_reason, batch_id,
              authorized_at, captured_at, settled_at, voided_at, refunded_at, failed_at,
              created_at, updated_at
```

## Payment Command

`App\Commands\Payment` — the primary interactive shell (`php payment-cli payment`) for the full transaction pipeline. Extends the `Shell` abstract base and owns two injected services: `CurrencyService` and `TransactionService`.

| Command | Arguments | Effect |
|---------|-----------|--------|
| `CREATE` | `<payment_id> <amount> <currency> <merchant_id>` | Creates an `INITIATED` transaction; idempotent on exact duplicate |
| `AUTHORIZE` / `AUTH` | `<payment_id>` | Transitions to `AUTHORIZED` or `PRE_SETTLEMENT_REVIEW` |
| `CAPTURE` | `<payment_id>` | Transitions to `CAPTURED` |
| `VOID` | `<payment_id> [reason]` | Transitions to `VOIDED`; optional reason string stored on the row |
| `REFUND` | `<payment_id> [amount]` | Transitions to `REFUNDED`; optional partial amount, defaults to full |
| `SETTLE` | `<payment_id>` | Transitions to `SETTLED`; increments account balance; idempotent |
| `SETTLEMENT` | `<batch_id>` | Read-only report — counts all `SETTLED` transactions and sums MYR total |
| `STATUS` | `<payment_id>` | Prints `payment_id status amount currency merchant_id` for one transaction |
| `LIST` | _(none)_ | Prints `payment_id status amount currency` for every transaction |
| `AUDIT` | `<payment_id>` | Accepted but currently a no-op stub |
| `HELP` | _(none)_ | Displays command reference |
| `EXIT` / `QUIT` | _(none)_ | Exits the shell |

- `CREATE` validates amount format (`/^\d+(\.\d{1,2})?$/`) and currency format (`/^[A-Z]{3}$/`) before delegating to `TransactionService::create()`.
- `AUTH` is registered as an alias for `AUTHORIZE` in the `dispatchCommand` match expression.
- `SETTLEMENT` scans all `SETTLED` rows; it is a reporting-only command and does not transition any status.
- All state-transition commands delegate entirely to `TransactionService::update()`; the command layer only formats the response string.

## Account Command

`App\Commands\Account` — a second interactive shell (`php payment-cli account`) for inspecting and resetting the application account. Extends the same `Shell` abstract base as `Payment`.

| Command | Effect |
|---------|--------|
| `INFO` | Prints account ID, MYR balance, creation timestamp, and all transactions (payment_id, status, amount, currency, merchant_id; plus refund_amount and void_reason when present) |
| `RESET` | Deletes all transaction rows and zeros the account balance — **destructive, no confirmation prompt** |
| `HELP` | Lists available commands |
| `EXIT` / `QUIT` | Exits the shell |

- Balance displayed by `INFO` is converted from minor units (`balance / 100`) and formatted to 2 decimal places.
- `RESET` is intended for development/testing workflows. In production this would require an explicit confirmation guard.

## Key Design Decisions

- **One account per app instance** — the account is a balance holder, not a merchant identity
- **Shell startup** → check if any account exists → if not, `INSERT` with `balance = 0.00`
- **Balance updates**: `+amount` on `SETTLE`, `-refund_amount` on `REFUND`, no change on `VOID`/`FAILED`
- **`merchant_id` on transactions** — used for filtering/reporting, not for account lookup
- **Integer amounts** — `amount`, `refund_amount`, and `balance` are stored as `BIGINT UNSIGNED` in minor units (e.g. `1000` = MYR 10.00). Decimal conversion is handled at the application layer based on currency exponent
- **`TransactionStatus` enum** — all valid statuses live in `app/Enums/TransactionStatus.php` as a backed string enum. Migration derives values via `array_column(TransactionStatus::cases(), 'value')` — never hardcode status strings elsewhere
- **Model cast** — `Transaction::$casts` maps `status` to `TransactionStatus::class`, so `$transaction->status` is always an enum instance

---

# Transaction Lifecycle

## Commands

| Command | From status | To status | Balance impact |
|---------|------------|-----------|----------------|
| `CREATE` | — | `INITIATED` | None |
| `AUTHORIZE` | `INITIATED` | `AUTHORIZED` or `PRE_SETTLEMENT_REVIEW` | None |
| `CAPTURE` | `AUTHORIZED` \| `PRE_SETTLEMENT_REVIEW` | `CAPTURED` | None |
| `SETTLE` | `CAPTURED` | `SETTLED` | `+amount` (MYR) |
| `VOID` | `INITIATED` \| `AUTHORIZED` | `VOIDED` | None |
| `REFUND` | `CAPTURED` | `REFUNDED` | `-refund_amount` (MYR) |
| `SETTLEMENT` | _(read-only report)_ | — | None |

## Descriptions

- **CREATE** — registers a transaction intent. Produces an `INITIATED` record. No money moves.
- **AUTHORIZE** — verifies and reserves the funds. Transitions to `PRE_SETTLEMENT_REVIEW` if amount ≥ MYR 1,000.00 (100,000 minor units). Still no balance change.
- **CAPTURE** — commits the reserved funds for collection. Balance still unchanged until `SETTLE`.
- **SETTLE** — finalises collection; increments account balance by the amount converted to MYR. Idempotent if already `SETTLED`.
- **VOID** — cancels before capture. No balance change. Optional `reason` string.
- **REFUND** — returns money after capture. Decrements account balance by the refund amount (MYR). Optional partial amount; defaults to full amount.
- **SETTLEMENT** — read-only batch report. Accepts a `batch_id` label and returns count + total MYR of all `SETTLED` transactions. Does not change any status.

## Statuses

| Status | Meaning |
|--------|---------|
| `INITIATED` | Transaction created, awaiting authorization |
| `AUTHORIZED` | Funds reserved/held on payer's side |
| `PRE_SETTLEMENT_REVIEW` | Authorized but flagged for review — amount ≥ MYR 1,000.00 |
| `CAPTURED` | Funds committed for collection, pending settlement |
| `SETTLED` | Funds collected, account balance updated |
| `VOIDED` | Cancelled before capture, no money moved |
| `REFUNDED` | Money returned after capture, balance decremented |
| `FAILED` | Set automatically by `CREATE` when a duplicate `payment_id` is submitted with mismatched `amount`, `currency`, or `merchant_id` |

## Happy Path

```
CREATE → AUTHORIZE → CAPTURE → SETTLE
```

## Cancellation Paths

```
CREATE → AUTHORIZE → VOID          (before capture)
CREATE → AUTHORIZE → CAPTURE → REFUND   (after capture)
```

---

# Currency

## Service

`App\Services\CurrencyService` — wraps `brick/money` (`^0.11.2`) with a `ConfigurableProvider`.

- **Base currency**: `MYR`
- **28 supported currencies** — see `docs/exchange-rates.md` for the full table
- **Rates source**: BNM Interbank Foreign Exchange Market, mid-market as of 2026-05-21
- **Cross-rates** route through MYR (e.g. USD → SGD goes USD → MYR → SGD)
- **Reciprocal rates** (MYR → X) are derived at construction via `bcdiv('1', $rate, 10)`
- Instantiated in `Shell::__construct()` and available as `$this->currencyService`

## Key Methods

| Method | Input/Output |
|--------|-------------|
| `convertMinorUnits(int $amount, string $from, string $to): int` | Minor units in → minor units out |
| `convert(Money $money, string $to): Money` | `Money` instance in → `Money` instance out |
| `supports(string $code): bool` | Check if an ISO code is supported before use |
| `supportedCurrencies(): array` | Returns all 28 supported ISO codes |

## Design Decisions

- Non-1 unit base currencies (JPY, HKD, IDR, etc.) are normalised to per-1-unit at declaration time — the table in `docs/exchange-rates.md` shows original units for reference
- `RoundingMode::HalfUp` applied at each conversion step (PascalCase — `HALF_UP` is deprecated in `brick/math`)
- Unsupported currencies throw `CurrencyConversionException` from `brick/money`