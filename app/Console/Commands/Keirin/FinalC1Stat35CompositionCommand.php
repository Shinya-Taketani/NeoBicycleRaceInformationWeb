<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Experiment;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Repackage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class FinalC1Stat35CompositionCommand extends Command
{
    protected $signature = 'keirin:c1:stat35-composition-final {mode=plan} {--source-result=} {--output-dir=}';

    protected $description = 'Fit final development C2 twice and compose with byte-exact final C1.';

    public function handle(): int
    {
        try {
            if ($this->argument('mode') === 'plan') {
                $result = Contract::plan();
            } elseif ($this->argument('mode') === 'execute') {
                self::denyExternalAccess();
                $result = app(Experiment::class)->execute((string) $this->option('output-dir'));
            } elseif ($this->argument('mode') === 'repackage') {
                self::denyExternalAccess();
                $result = app(Repackage::class)->run((string) $this->option('source-result'), (string) $this->option('output-dir'));
            } else {
                throw new RuntimeException('Use plan, execute, or pinned repackage.');
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());
            $this->line(json_encode(['status' => 'FAILED_NOT_PUBLISHED', 'performance' => null, 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    public static function denyExternalAccess(): void
    {
        DB::swap(new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('Database access forbidden for final composition.');
            }
        });
        Http::fake(static fn () => throw new RuntimeException('HTTP access forbidden for final composition.'));
    }
}
