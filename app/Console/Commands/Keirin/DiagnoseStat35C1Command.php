<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract as Comparison;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Builder;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Contract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DiagnoseStat35C1Command extends Command
{
    protected $signature = 'keirin:stat35:c1-diagnostic {mode=plan} {--output-dir=} {--original-dir=}';

    protected $description = 'Offline post-hoc diagnostics of fixed C1/C2 utilities and saved Primary decisions.';

    public function handle(Builder $builder): int
    {
        try {
            if ($this->argument('mode') === 'plan') {
                $result = Contract::plan();
            } else {
                $mode = $this->argument('mode');
                $output = (string) $this->option('output-dir');
                $original = $this->option('original-dir');
                $parent = realpath(dirname($output));
                $root = realpath(Contract::ROOT);
                if (! in_array($mode, ['build', 'reproduce'], true) || $root === false || $parent === false
                    || ($parent !== $root && ! str_starts_with($parent.'/', $root.'/'))
                    || ($mode === 'reproduce' ? ! is_string($original) || $original === '' : $original !== null)) {
                    throw new RuntimeException('Use plan/build/reproduce; output must be new inside the agreed root.');
                }
                DB::swap(new class
                {
                    public function __call(string $method, array $arguments): never
                    {
                        throw new RuntimeException('Application DB access forbidden for diagnostics.');
                    }
                });
                Http::fake(static fn () => throw new RuntimeException('HTTP access forbidden for diagnostics.'));
                $result = $builder->build(Contract::COMPARE, Comparison::INPUT, Comparison::BASELINE, $output, $original);
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
