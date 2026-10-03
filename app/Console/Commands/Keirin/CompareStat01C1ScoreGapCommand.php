<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\Stat01C1ScoreGapComparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat01C1ScoreGapComparison\Experiment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CompareStat01C1ScoreGapCommand extends Command
{
    protected $signature = 'keirin:stat01:c1-score-gap {mode=plan} {--output-dir=}';

    protected $description = 'Fixed C1 race-score gap comparison and independent reproduction, offline.';

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
                // Never fall back to application DB/HTTP, even during exception handling.
                DB::swap(new class
                {
                    public function __call(string $name, array $arguments): never
                    {
                        throw new RuntimeException('Application DB access forbidden for C1_PLUS_SCORE_GAP comparison.');
                    }
                });
                Http::fake(static fn () => throw new RuntimeException('HTTP access forbidden for C1_PLUS_SCORE_GAP comparison.'));
                $result = $experiment->execute(Contract::INPUT, Contract::BASELINE, $output);
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
