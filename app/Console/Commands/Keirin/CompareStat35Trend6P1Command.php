<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Experiment;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\RetainedBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\RetainedSpoolDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CompareStat35Trend6P1Command extends Command
{
    protected $signature = 'keirin:stat35:trend6-p1 {mode=plan} {--output-dir=}';

    protected $description = 'Sealed INPUT02 trend6 C3-only training and paired Outer comparison, offline.';

    public function handle(): int
    {
        try {
            $mode = $this->argument('mode');
            if ($mode === 'plan') {
                $result = Contract::plan();
            } else {
                $output = (string) $this->option('output-dir');
                $parent = realpath(dirname($output));
                $root = realpath(Contract::ROOT);
                if ($mode !== 'execute' || $root === false || $parent === false || ($root !== $parent && ! str_starts_with($parent.'/', $root.'/'))) {
                    throw new RuntimeException('New output inside agreed experiment root required.');
                }
                DB::swap(new class
                {
                    public function __call(string $method, array $arguments): never
                    {
                        throw new RuntimeException('Application DB access forbidden.');
                    }
                });
                Http::fake(static fn () => throw new RuntimeException('HTTP forbidden.'));
                // Resolve the trainer only after binding a numerically identical, nondeleting spool adapter.
                $this->bindRetainedSpools(dirname($output));
                $result = $this->laravel->make(Experiment::class)->execute(Contract::INPUT, Contract::BASELINE, Contract::COMPOSITION, $output);
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function bindRetainedSpools(string $parent): string
    {
        $spools = $parent.'/bin-spools-'.bin2hex(random_bytes(8));
        if (! mkdir($spools, 0700)) {
            throw new RuntimeException('Cannot create dedicated retained bin spools.');
        }
        RetainedSpoolDirectory::recordCreation($spools);
        $this->laravel->instance(EffectBinBoundaryProvider::class, new RetainedBinBoundaryProvider($spools));

        return $spools;
    }
}
