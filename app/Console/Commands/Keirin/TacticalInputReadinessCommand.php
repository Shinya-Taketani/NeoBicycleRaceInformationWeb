<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\TacticalInputReadiness\Auditor;
use App\Domain\Keirin\Statistics\TacticalInputReadiness\Builder;
use App\Domain\Keirin\Statistics\TacticalInputReadiness\Contract;
use Illuminate\Console\Command;
use RuntimeException;

final class TacticalInputReadinessCommand extends Command
{
    protected $signature = 'keirin:c1:tactical-input-readiness {mode=plan} {--authorize-read-only} {--snapshot=} {--original=} {--output-dir=}';

    protected $description = 'Audit fixed 2022-2025 racecard attributes read-only; build and reproduce unapproved candidates offline.';

    public function handle(Auditor $auditor, Builder $builder): int
    {
        $start = microtime(true);
        try {
            $mode = $this->argument('mode');
            if ($mode !== 'audit' && $this->option('authorize-read-only')) {
                throw new RuntimeException('Only audit can authorize database access.');
            }
            if (($mode === 'reproduce') !== ($this->option('original') !== null)) {
                throw new RuntimeException('Only reproduce requires original.');
            }
            $report = match ($mode) {
                'plan' => Contract::plan(),
                'audit' => $auditor->audit((string) $this->option('output-dir'), (bool) $this->option('authorize-read-only')),
                'build', 'reproduce' => $builder->build((string) $this->option('snapshot'), (string) $this->option('output-dir'), $this->option('original')),
                default => throw new RuntimeException('Use plan/audit/build/reproduce.'),
            };
            $this->line(json_encode($report + ['elapsed_seconds' => microtime(true) - $start,
                'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
