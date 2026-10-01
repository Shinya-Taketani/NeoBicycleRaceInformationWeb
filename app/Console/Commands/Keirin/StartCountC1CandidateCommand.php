<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\StartCountC1Candidate\Builder;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Contract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class StartCountC1CandidateCommand extends Command
{
    protected $signature = 'keirin:stat36:c1-candidate {mode=plan} {--output-dir=} {--original-dir=}';

    protected $description = 'Fixed C1 display S count review candidates; training/evaluation is blocked.';

    public function handle(Builder $builder): int
    {
        try {
            umask(0077);
            DB::swap(new class
            {
                public function __call(string $name, array $arguments): never
                {
                    throw new RuntimeException('Application DB forbidden.');
                }
            });
            Http::fake(static fn () => throw new RuntimeException('HTTP forbidden.'));
            $mode = $this->argument('mode');
            if ($mode === 'plan') {
                $result = Contract::plan();
            } else {
                $output = (string) $this->option('output-dir');
                $root = realpath(Contract::ROOT);
                $parent = realpath(dirname($output));
                $original = $this->option('original-dir');
                if (! in_array($mode, ['build', 'reproduce'], true) || $root === false || $parent === false
                    || ($parent !== $root && ! str_starts_with($parent.'/', $root.'/')) || $output !== $parent.'/'.basename($output)
                    || ($mode === 'reproduce' ? ! is_string($original) || $original === '' : $original !== null)) {
                    throw new RuntimeException('New output under agreed root and valid mode/original required.');
                }
                $result = $builder->build($output, $original);
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
