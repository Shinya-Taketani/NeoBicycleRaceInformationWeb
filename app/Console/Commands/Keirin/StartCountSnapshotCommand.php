<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\StartCountSnapshot\Builder;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Contract;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Exporter;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Inspector;
use App\Domain\Keirin\Statistics\StartObservation\Contract as LedgerContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class StartCountSnapshotCommand extends Command
{
    protected $signature = 'keirin:stat36:start-count {mode=plan} {--source-dir=} {--output-dir=} {--original-dir=}';

    protected $description = 'Saved PJ0315 S count snapshots, not race-level events or prediction inputs.';

    public function handle(Builder $builder, Exporter $exporter, Inspector $inspector): int
    {
        $mode = $this->argument('mode');
        try {
            umask(0077);
            Http::fake(static fn () => throw new RuntimeException('HTTP forbidden.'));
            if ($mode !== 'export') {
                DB::swap(new class
                {
                    public function __call(string $method, array $args): never
                    {
                        throw new RuntimeException('Application DB forbidden.');
                    }
                });
            }
            if ($mode === 'plan') {
                $result = Contract::plan();
            } else {
                $output = (string) $this->option('output-dir');
                $parent = realpath(dirname($output));
                $root = realpath(Contract::ROOT);
                if (! in_array($mode, ['export', 'inspect', 'build', 'reproduce'], true) || $root === false || $parent === false
                    || ($parent !== $root && ! str_starts_with($parent.'/', $root.'/')) || $output !== $parent.'/'.basename($output)) {
                    throw new RuntimeException('New canonical output within agreed root required.');
                }
                $source = $this->option('source-dir');
                $original = $this->option('original-dir');
                if (($mode === 'export' && ($source !== null || $original !== null))
                    || ($mode !== 'export' && (! is_string($source) || $source === ''))
                    || ($mode === 'reproduce' ? ! is_string($original) || $original === '' : $original !== null)) {
                    throw new RuntimeException('Invalid source/original arguments.');
                }
                $result = $mode === 'export' ? $exporter->export(LedgerContract::LEDGER, $output)
                    : ($mode === 'inspect' ? $inspector->inspect($source, $output) : $builder->build($source, $output, $original));
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error::class.($mode === 'export' ? ': export failed; no retry, private SQL bindings withheld.' : ': '.$error->getMessage()));

            return self::FAILURE;
        }
    }
}
