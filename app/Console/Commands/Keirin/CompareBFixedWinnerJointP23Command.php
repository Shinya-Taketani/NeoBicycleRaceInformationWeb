<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Contract;
use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Experiment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CompareBFixedWinnerJointP23Command extends Command
{
    protected $signature = 'keirin:b:fixed-winner-joint-p23 {mode=plan} {--output-dir=}';

    protected $description = 'Compare one fixed-winner joint P2/P3 decoder on saved development predictions.';

    public function handle(Experiment $experiment): int
    {
        try {
            if ($this->argument('mode') === 'plan') {
                $result = Contract::plan();
            } else {
                $output = (string) $this->option('output-dir');
                $root = realpath(Contract::ROOT);
                $parent = realpath(dirname($output));
                if ($this->argument('mode') !== 'execute' || $root === false || $parent === false
                    || ($parent !== $root && ! str_starts_with($parent.'/', $root.'/'))) {
                    throw new RuntimeException('New output must be inside the agreed experiment root.');
                }
                DB::swap(new class
                {
                    public function __call(string $name, array $arguments): never
                    {
                        throw new RuntimeException('Business DB access forbidden.');
                    }
                });
                Http::fake(static fn () => throw new RuntimeException('HTTP forbidden.'));
                $result = $experiment->execute($output);
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());
            $this->line(json_encode(['evaluation_status' => 'NOT_EVALUATED', 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
