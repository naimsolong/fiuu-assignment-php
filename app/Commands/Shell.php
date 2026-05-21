<?php

namespace App\Commands;

use App\Models\Transaction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use LaravelZero\Framework\Commands\Command;
use function Termwind\{render};

class Shell extends Command
{
    const VERBS = [
        'Accomplishing', 'Actioning', 'Actualizing', 'Architecting', 'Baking', 'Beaming', 'Bebopping',
        'Befuddling', 'Billowing', 'Blanching', 'Bloviating', 'Boogieing', 'Boondoggling', 'Booping', 'Bootstrapping', 'Brewing',
        'Bunning', 'Burrowing', 'Calculating', 'Canoodling', 'Caramelizing', 'Cascading', 'Catapulting', 'Cerebrating', 'Channeling',
        'Channelling', 'Choreographing', 'Churning', 'Clauding', 'Coalescing', 'Cogitating', 'Combobulating', 'Composing', 'Computing',
        'Concocting', 'Deliberating', 'Determining', 'Dilly-dallying', 'Discombobulating', 'Doing', 'Doodling', 'Drizzling', 'Ebbing',
        'Effecting', 'Elucidating', 'Embellishing', 'Enchanting', 'Envisioning', 'Evaporating', 'Fermenting', 'Fiddle-faddling',
        'Finagling', 'Flambéing', 'Flibbertigibbeting', 'Flowing', 'Flummoxing', 'Fluttering', 'Forging', 'Forming', 'Frolicking',
        'Frosting', 'Gallivanting', 'Galloping', 'Garnishing', 'Generating', 'Gesticulating', 'Germinating', 'Gitifying', 'Grooving',
        'Gusting', 'Harmonizing', 'Hashing', 'Hatching', 'Herding', 'Honking', 'Hullaballooing', 'Hyperspacing', 'Ideating', 'Imagining',
        'Improvising', 'Incubating', 'Inferring', 'Infusing', 'Ionizing', 'Jitterbugging', 'Julienning', 'Kneading', 'Leavening',
        'Levitating', 'Lollygagging', 'Manifesting', 'Marinating', 'Meandering', 'Metamorphosing', 'Misting', 'Moonwalking', 'Moseying',
        'Mulling', 'Mustering', 'Musing', 'Nebulizing', 'Nesting', 'Newspapering', 'Noodling', 'Nucleating', 'Orbiting', 'Orchestrating',
        'Osmosing', 'Perambulating', 'Percolating', 'Perusing', 'Philosophising', 'Photosynthesizing', 'Pollinating', 'Pondering',
        'Pontificating', 'Pouncing', 'Precipitating', 'Prestidigitating', 'Processing', 'Proofing', 'Propagating', 'Puttering',
        'Puzzling', 'Quantumizing', 'Razzle-dazzling', 'Razzmatazzing', 'Recombobulating', 'Reticulating', 'Roosting', 'Ruminating',
        'Sautéing', 'Scampering', 'Schlepping', 'Scurrying', 'Seasoning', 'Shenaniganing', 'Shimmying', 'Simmering', 'Skedaddling',
        'Sketching', 'Slithering', 'Smooshing', 'Sock-hopping', 'Spelunking', 'Spinning', 'Sprouting', 'Stewing', 'Sublimating',
        'Swirling', 'Swooping', 'Symbioting', 'Synthesizing', 'Tempering', 'Thinking', 'Thundering', 'Tinkering', 'Tomfoolering',
        'Topsy-turvying', 'Transfiguring', 'Transmuting', 'Twisting', 'Undulating', 'Unfurling', 'Unravelling', 'Vibing', 'Waddling',
        'Wandering', 'Warping', 'Whatchamacalliting', 'Whirlpooling', 'Whirring', 'Whisking', 'Wibbling', 'Working', 'Wrangling',
        'Zesting', 'Zigzagging',
    ];

    protected $signature = 'shell {file? : The command file to process}';

    protected $description = 'Interactive transaction shell - accepts CREATE, AUTHORIZE, CAPTURE, VOID, REFUND, SETTLE, STATUS, LIST, EXIT';

    public function handle(): int
    {
        $file = $this->argument('file');

        if ($file) {
            $this->processFile($file);
        } else {
            $this->runInteractive();
        }

        return 0;
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

    protected function processFile(string $filepath): void
    {
        if (!file_exists($filepath)) {
            $this->error("File not found: $filepath");
            return;
        }

        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lineNumber = 0;
        $totalLines = count($lines);

        $this->info("Processing $totalLines commands...");
        $this->newLine();

        $verbIndex = 0;
        $verbs = self::VERBS;
        $spinner = ['|', '/', '-', '\\'];
        $spinnerIndex = 0;

        foreach ($lines as $line) {
            $lineNumber++;

            // Spinning loader animation
            $currentVerb = $verbs[$verbIndex % count($verbs)];
            $currentSpinner = $spinner[$spinnerIndex % count($spinner)];
            $this->output->write("\r{$currentSpinner} $currentVerb...");

            $result = $this->processCommand($line);

            $verbIndex++;
            $spinnerIndex++;

            if (!empty(trim($line))) {
                $this->output->write("\r" . str_repeat(' ', 20)); // Clear spinner line
                $this->newLine();
                $this->line("$lineNumber> $result");
            }
        }

        $this->output->write("\r" . str_repeat(' ', 20));
        $this->newLine(2);
        $this->info("Done! Processed $lineNumber commands.");
    }

    protected function processCommand(string $line): string
    {
        $line = trim($line);

        if ($line === '') {
            return '';
        }

        // Handle inline comments - only after 3rd token
        $tokens = preg_split('/\s+/', $line);
        $command = strtoupper($tokens[0] ?? '');

        // Skip empty command
        if ($command === '') {
            return '';
        }

        // Check if # appears after position 3 (index 3 = 4th token)
        if (count($tokens) >= 4) {
            $inlinePosition = strpos($line, '#');
            if ($inlinePosition !== false) {
                $beforeThirdArg = explode(' ', $line, 4);
                if (count($beforeThirdArg) >= 4) {
                    $line = trim($beforeThirdArg[0] . ' ' . $beforeThirdArg[1] . ' ' . $beforeThirdArg[2]);
                    $tokens = preg_split('/\s+/', $line);
                    $command = strtoupper($tokens[0] ?? '');
                }
            }
        }

        return match ($command) {
            'HELP' => $this->help(),
            'CREATE' => $this->cmdCreate($tokens),
            'AUTHORIZE', 'AUTH' => $this->cmdAuthorize($tokens),
            'CAPTURE' => $this->cmdCapture($tokens),
            'VOID' => $this->cmdVoid($tokens),
            'REFUND' => $this->cmdRefund($tokens),
            'SETTLE' => $this->cmdSettle($tokens),
            'SETTLEMENT' => $this->cmdSettlement($tokens),
            'STATUS' => $this->cmdStatus($tokens),
            'LIST' => $this->cmdList($tokens),
            'AUDIT' => 'AUDIT RECEIVED',
            'EXIT', 'QUIT' => 'Goodbye!',
            default => "ERROR: Unknown command '$command'",
        };
    }

    protected function help(): string
    {
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
        return '';
    }

    protected function cmdAuthorize(array $tokens): string
    {
        return '';
    }

    protected function cmdCapture(array $tokens): string
    {
        return '';
    }

    protected function cmdVoid(array $tokens): string
    {
        return '';
    }

    protected function cmdRefund(array $tokens): string
    {
        return '';
    }

    protected function cmdSettle(array $tokens): string
    {
        return '';
    }

    protected function cmdSettlement(array $tokens): string
    {
        return '';
    }

    protected function cmdStatus(array $tokens): string
    {
        return '';
    }

    protected function cmdList(array $tokens): string
    {
        return '';
    }

    public function schedule(Schedule $schedule): void
    {
        // not scheduled
    }
}
