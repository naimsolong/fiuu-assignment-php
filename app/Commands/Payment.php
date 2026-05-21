<?php

namespace App\Commands;

use App\Commands\Concerns\Shell;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\CurrencyService;
use App\Services\TransactionService;
use function Termwind\{render, renderUsing};

class Payment extends Shell
{
    protected CurrencyService $currencyService;

    protected TransactionService $transactionService;

    protected $signature = 'payment {args* : Command and arguments (omit to start interactive shell)}';

    protected $description = 'Transaction shell — run interactively or pass a command directly (e.g. payment CREATE P101 100 MYR 1)';

    public function __construct()
    {
        parent::__construct();

        $this->currencyService    = new CurrencyService();
        $this->transactionService = new TransactionService($this->currencyService);
    }

    public function handle(): int
    {
        $this->initiate();
        $this->runOnceOrInteractive();

        return 0;
    }

    protected function dispatchCommand(string $command, array $tokens): string
    {
        return match ($command) {
            'HELP'                  => $this->help(),
            'CREATE'                => $this->cmdCreate($tokens),
            'AUTHORIZE', 'AUTH'     => $this->cmdAuthorize($tokens),
            'CAPTURE'               => $this->cmdCapture($tokens),
            'VOID'                  => $this->cmdVoid($tokens),
            'REFUND'                => $this->cmdRefund($tokens),
            'SETTLE'                => $this->cmdSettle($tokens),
            'SETTLEMENT'            => $this->cmdSettlement($tokens),
            'STATUS'                => $this->cmdStatus($tokens),
            'LIST'                  => $this->cmdList($tokens),
            'AUDIT'                 => 'AUDIT RECEIVED',
            default                 => "ERROR: Unknown command '$command'",
        };
    }

    protected function help(): string
    {
        renderUsing($this->output);
        render(<<<'HTML'
<div>
<div class="font-bold text-yellow mb-1">Commands:</div>
<div><span class="text-green">CREATE</span> <span class="text-white">&lt;id&gt; &lt;amount&gt; &lt;currency&gt; &lt;merchant_id&gt;</span><br><span class="mx-1"></span><span class="text-gray">- Create transaction</span></div>
<div><span class="text-green">AUTHORIZE</span> <span class="text-white">&lt;id&gt;</span><br><span class="mx-1"></span><span class="text-gray">- Authorize transaction</span></div>
<div><span class="text-green">CAPTURE</span> <span class="text-white">&lt;id&gt;</span><br><span class="mx-1"></span><span class="text-gray">- Capture transaction</span></div>
<div><span class="text-green">VOID</span> <span class="text-white">&lt;id&gt; [reason]</span><br><span class="mx-1"></span><span class="text-gray">- Void transaction</span></div>
<div><span class="text-green">REFUND</span> <span class="text-white">&lt;id&gt; [amount]</span><br><span class="mx-1"></span><span class="text-gray">- Refund transaction</span></div>
<div><span class="text-green">SETTLE</span> <span class="text-white">&lt;id&gt;</span><br><span class="mx-1"></span><span class="text-gray">- Settle transaction</span></div>
<div><span class="text-green">SETTLEMENT</span> <span class="text-white">&lt;batch_id&gt;</span><br><span class="mx-1"></span><span class="text-gray">- Settlement batch (reporting)</span></div>
<div><span class="text-green">STATUS</span> <span class="text-white">&lt;id&gt;</span><br><span class="mx-1"></span><span class="text-gray">- Show transaction status</span></div>
<div><span class="text-green">LIST</span><br><span class="mx-1"></span><span class="text-gray">- List all transactions</span></div>
<div><span class="text-green">AUDIT</span> <span class="text-white">&lt;id&gt;</span><br><span class="mx-1"></span><span class="text-gray">- Audit (no effect)</span></div>
<div><span class="text-green">EXIT</span><br><span class="mx-1"></span><span class="text-gray">- Exit shell</span></div>
</div>
HTML
        );

        return '';
    }

    protected function cmdCreate(array $tokens): string
    {
        if (count($tokens) < 4) {
            return 'ERROR: CREATE requires <payment_id> <amount> <currency> <merchant_id>';
        }

        $id         = $tokens[1];
        $amount     = $tokens[2];
        $currency   = strtoupper($tokens[3]);
        $merchantId = $tokens[4] ?? '';

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return "ERROR: Invalid amount '$amount'";
        }

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return "ERROR: Invalid currency '$currency' (must be 3 letters)";
        }

        try {
            $result = $this->transactionService->create($id, $amount, $currency, $merchantId);
            if ($result['idempotent']) {
                return "OK: IDEMPOTENT - no change";
            }
            return "OK: Created $id ($amount $currency) state=INITIATED";
        } catch (\RuntimeException $e) {
            return "ERROR: {$e->getMessage()}";
        }
    }

    protected function cmdAuthorize(array $tokens): string
    {
        if (count($tokens) < 2) {
            return 'ERROR: AUTHORIZE requires <payment_id>';
        }

        $id = $tokens[1];

        try {
            $result = $this->transactionService->update($id, 'AUTHORIZE');
            $t = $result['transaction'];
            if ($t->status === TransactionStatus::PreSettlementReview) {
                return "OK: $id state=AUTHORIZED→PRE_SETTLEMENT_REVIEW (threshold review)";
            }
            return "OK: $id state=AUTHORIZED";
        } catch (\RuntimeException $e) {
            return "ERROR: {$e->getMessage()}";
        }
    }

    protected function cmdCapture(array $tokens): string
    {
        if (count($tokens) < 2) {
            return 'ERROR: CAPTURE requires <payment_id>';
        }

        $id = $tokens[1];

        try {
            $this->transactionService->update($id, 'CAPTURE');
            return "OK: $id state=CAPTURED";
        } catch (\RuntimeException $e) {
            return "ERROR: {$e->getMessage()}";
        }
    }

    protected function cmdVoid(array $tokens): string
    {
        if (count($tokens) < 2) {
            return 'ERROR: VOID requires <payment_id>';
        }

        $id     = $tokens[1];
        $reason = $tokens[2] ?? '';

        try {
            $this->transactionService->update($id, 'VOID', ['reason' => $reason]);
            return "OK: $id state=VOIDED" . ($reason ? " reason=$reason" : "");
        } catch (\RuntimeException $e) {
            return "ERROR: {$e->getMessage()}";
        }
    }

    protected function cmdRefund(array $tokens): string
    {
        if (count($tokens) < 2) {
            return 'ERROR: REFUND requires <payment_id>';
        }

        $id     = $tokens[1];
        $amount = $tokens[2] ?? null;

        try {
            $this->transactionService->update($id, 'REFUND', array_filter(['amount' => $amount], fn ($v) => $v !== null));
            return $amount ? "OK: $id state=REFUNDED amount=$amount" : "OK: $id state=REFUNDED";
        } catch (\RuntimeException $e) {
            return "ERROR: {$e->getMessage()}";
        }
    }

    protected function cmdSettle(array $tokens): string
    {
        if (count($tokens) < 2) {
            return 'ERROR: SETTLE requires <payment_id>';
        }

        $id = $tokens[1];

        try {
            $result = $this->transactionService->update($id, 'SETTLE');
            if ($result['idempotent']) {
                return "OK: Idempotent - no change (already SETTLED)";
            }
            return "OK: $id state=SETTLED";
        } catch (\RuntimeException $e) {
            return "ERROR: {$e->getMessage()}";
        }
    }

    protected function cmdSettlement(array $tokens): string
    {
        if (count($tokens) < 2) {
            return 'ERROR: SETTLEMENT requires <batch_id>';
        }

        $batchId  = $tokens[1];

        Transaction::where('status', TransactionStatus::Settled->value)
            ->whereNull('batch_id')
            ->update(['batch_id' => $batchId]);

        $settled  = Transaction::where('status', TransactionStatus::Settled->value)->get();
        $count    = $settled->count();
        $totalMyr = $settled->sum(fn ($t) => $this->currencyService->convertMinorUnits($t->amount, $t->currency, 'MYR'));

        return "OK: Batch $batchId settled=$count transactions total=$totalMyr";
    }

    protected function cmdStatus(array $tokens): string
    {
        if (count($tokens) < 2) {
            return 'ERROR: STATUS requires <payment_id>';
        }

        $id          = $tokens[1];
        $transaction = Transaction::findByPaymentId($id);

        if (!$transaction) {
            return "ERROR: Transaction '$id' not found";
        }

        return "{$transaction->payment_id} {$transaction->status->value} {$transaction->amount} {$transaction->currency} {$transaction->merchant_id}";
    }

    protected function cmdList(array $tokens): string
    {
        $transactions = Transaction::all();

        if ($transactions->isEmpty()) {
            return 'No transactions found';
        }

        $output = [];
        foreach ($transactions as $t) {
            $output[] = "{$t->payment_id} {$t->status->value} {$t->amount} {$t->currency}";
        }

        return implode("\n", $output);
    }
}
