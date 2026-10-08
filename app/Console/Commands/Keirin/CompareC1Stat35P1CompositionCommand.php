<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Contract as SourceContract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Experiment;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Contract as Diagnostic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CompareC1Stat35P1CompositionCommand extends Command
{
    protected $signature = 'keirin:c1:stat35-p1-composition {mode=plan} {--output-dir=}';

    protected $description = 'Compose saved C2 P1 and C1 P2/P3 utilities without training.';

    public function handle(Experiment $experiment): int
    {
        try {
            if ($this->argument('mode') === 'plan') {
                $result = Contract::plan();
            } else {
                $output = (string) $this->option('output-dir');
                $parent = realpath(dirname($output));
                $root = realpath(Contract::ROOT);
                if ($this->argument('mode') !== 'execute' || $root === false || $parent === false
                    || ($parent !== $root && ! str_starts_with($parent.'/', $root.'/'))) {
                    throw new RuntimeException('Use plan or execute with a new output inside the agreed root.');
                }
                DB::swap(new class
                {
                    public function __call(string $name, array $arguments): never
                    {
                        throw new RuntimeException('DB access forbidden for fixed position utility composition.');
                    }
                });
                Http::fake(static fn () => throw new RuntimeException('HTTP access forbidden for fixed position utility composition.'));
                $result = $experiment->execute(SourceContract::INPUT, SourceContract::BASELINE, Diagnostic::COMPARE, $output);
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());
            $this->line(json_encode(['status' => 'NOT_EVALUATED', 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
