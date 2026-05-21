<?php

namespace App\Commands\Concerns;

use App\Models\Account;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;

abstract class Shell extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function initiate(): void
    {
        $dbDir = dirname(config('database.connections.sqlite.database'));
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        Artisan::call('migrate', ['--force' => true]);

        Account::ensureExists();
    }

    protected function runOnceOrInteractive(): void
    {
        $args = $this->argument('args');
        if (!empty($args)) {
            $result = $this->processCommand(implode(' ', $args));
            if ($result !== '') {
                $this->line($result);
            }
            return;
        }
        $this->runInteractive();
    }

    protected function runInteractive(): void
    {
        $this->info('Shell - Type "help" for commands, "exit" to quit.');
        $this->newLine();

        while (true) {
            $line = $this->ask('shell');

            if (trim($line) === '') continue;

            $result = $this->processCommand($line);
            $this->line($result);

            if (str_starts_with($result, 'Goodbye')) break;
        }
    }

    protected function processCommand(string $line): string
    {
        $line = trim($line);

        if ($line === '') {
            return '';
        }

        $tokens  = preg_split('/\s+/', $line);
        $command = strtoupper($tokens[0] ?? '');

        if ($command === '') {
            return '';
        }

        // Strip inline comment: # must appear as a standalone token at position >= 4
        // (after command + 3 argument tokens), per spec
        foreach ($tokens as $i => $tok) {
            if ($i >= 4 && str_starts_with($tok, '#')) {
                $tokens  = array_slice($tokens, 0, $i);
                $line    = implode(' ', $tokens);
                $command = strtoupper($tokens[0] ?? '');
                break;
            }
        }

        return match ($command) {
            'CLEAR'        => $this->cmdClear(),
            'EXIT', 'QUIT' => 'Goodbye!',
            default        => $this->dispatchCommand($command, $tokens),
        };
    }

    abstract protected function dispatchCommand(string $command, array $tokens): string;

    protected function cmdClear(): string
    {
        $this->output->write("\033[2J\033[H");

        return '';
    }
}
