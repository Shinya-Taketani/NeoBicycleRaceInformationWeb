<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\StartObservation\Builder;
use App\Domain\Keirin\Statistics\StartObservation\Contract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ObserveStat36Command extends Command
{
    protected $signature = 'keirin:stat36:observations {mode=plan} {--output-dir=} {--original-dir=}';

    protected $description = 'Offline import-version start-display observations; no inferred start or initial position.';

    public function handle(Builder $builder): int
    {
        try {
            DB::swap(new class
            {
                public function __call(string $method, array $arguments): never
                {
                    throw new RuntimeException('Application DB forbidden for STAT36 observations.');
                }
            });
            Http::fake(static fn () => throw new RuntimeException('HTTP forbidden for STAT36 observations.'));
            $mode = $this->argument('mode');
            if ($mode === 'plan') {
                $result = Contract::plan();
            } else {
                $output = (string) $this->option('output-dir');
                $original = $this->option('original-dir');
                $root = realpath(Contract::ROOT);
                $parent = realpath(dirname($output));
                if (! in_array($mode, ['build', 'reproduce'], true) || $root === false || $parent === false
                    || ($parent !== $root && ! str_starts_with($parent.'/', $root.'/'))
                    || ($mode === 'reproduce' ? ! is_string($original) || $original === '' : $original !== null)) {
                    throw new RuntimeException('Use plan/build/reproduce with a new output in the agreed root.');
                }
                $result = $builder->build(Contract::LEDGER, $output, $original);
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error::class.': '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
