<?php

namespace App\Commands;

use App\Commands\Concerns\Shell;
use App\Models\Account as AccountModel;
use App\Models\Transaction;
use Brick\Money\Money;
use function Termwind\{render, renderUsing};

class Account extends Shell
{
    protected $signature = 'account {args?* : Command and arguments (omit to start interactive shell)}';

    protected $description = 'Account shell — run interactively or pass a command directly (e.g. account INFO)';

    public function handle(): int
    {
        $this->initiate();
        $this->runOnceOrInteractive();

        return 0;
    }

    protected function dispatchCommand(string $command, array $tokens): string
    {
        return match ($command) {
            'HELP'  => $this->help(),
            'INFO'  => $this->cmdInfo(),
            'RESET' => $this->cmdReset(),
            default => "ERROR: Unknown command '$command'",
        };
    }

    protected function help(): string
    {
        renderUsing($this->output);
        render(<<<'HTML'
<div>
<div class="font-bold text-yellow mb-1">Commands:</div>
<div><span class="text-green">INFO</span><br><span class="mx-1"></span><span class="text-gray">- Display account details and all transactions</span></div>
<div><span class="text-green">RESET</span><br><span class="mx-1"></span><span class="text-gray">- Reset balance to zero and delete all transactions</span></div>
<div><span class="text-green">EXIT</span><br><span class="mx-1"></span><span class="text-gray">- Exit shell</span></div>
</div>
HTML
        );

        return '';
    }

    protected function cmdInfo(): string
    {
        $account      = AccountModel::with('transactions.refunds')->first();
        $balanceMyr   = number_format($account->balance / 100, 2);
        $transactions = $account->transactions;

        $lines   = [];
        $lines[] = "Account #{$account->id}";
        $lines[] = "Balance: MYR {$balanceMyr}";
        $lines[] = "Created: {$account->created_at}";

        if ($transactions->isEmpty()) {
            $lines[] = 'Transactions: none';
        } else {
            $lines[] = "Transactions ({$transactions->count()}):";
            foreach ($transactions as $t) {
                $line = "  {$t->payment_id} {$t->status->value} {$t->amount} {$t->currency} merchant={$t->merchant_id}";
                if ($t->void_reason) {
                    $line .= " reason={$t->void_reason}";
                }
                $lines[] = $line;

                foreach ($t->refunds as $i => $refund) {
                    $refDec  = Money::ofMinor($refund->amount, $refund->currency)->getAmount()->__toString();
                    $prefix  = $i === 0 ? '    refunds: ' : '             ';
                    $lines[] = "{$prefix}{$refDec} {$refund->currency} @ {$refund->created_at->format('Y-m-d H:i:s')}";
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function cmdReset(): string
    {
        $account = AccountModel::first();

        Transaction::query()->delete();
        $account->update(['balance' => 0]);

        return 'OK: Account reset - balance zeroed and all transactions deleted';
    }
}
