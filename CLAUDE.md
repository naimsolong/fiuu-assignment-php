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

## Key Design Decisions

- **One account per app instance** — the account is a balance holder, not a merchant identity
- **Shell startup** → check if any account exists → if not, `INSERT` with `balance = 0.00`
- **Balance updates**: `+amount` on `SETTLE`, `-refund_amount` on `REFUND`, no change on `VOID`/`FAILED`
- **`merchant_id` on transactions** — used for filtering/reporting, not for account lookup
- **Integer amounts** — `amount`, `refund_amount`, and `balance` are stored as `BIGINT UNSIGNED` in minor units (e.g. `1000` = MYR 10.00). Decimal conversion is handled at the application layer based on currency exponent
- **`TransactionStatus` enum** — all valid statuses live in `app/Enums/TransactionStatus.php` as a backed string enum. Migration derives values via `array_column(TransactionStatus::cases(), 'value')` — never hardcode status strings elsewhere
- **Model cast** — `Transaction::$casts` maps `status` to `TransactionStatus::class`, so `$transaction->status` is always an enum instance