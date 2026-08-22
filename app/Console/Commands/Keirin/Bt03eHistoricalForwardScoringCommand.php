<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03eHistoricalForwardScoringService;
use Illuminate\Console\Command;
use Throwable;

class Bt03eHistoricalForwardScoringCommand extends Command
{
    protected $signature = 'keirin:backtest:bt03e-historical-forward-score
        {--output-dir=/tmp : Non-Git directory for JSON and CSV artifacts}';

    protected $description = 'Train fixed integer points on 2023 and evaluate them out of sample on 2024.';

    public function handle(Bt03eHistoricalForwardScoringService $service): int
    {
        $directory = $this->option('output-dir');
        if (! is_string($directory) || $directory === '') {
            $this->error('--output-dir must be a non-empty path.');

            return self::FAILURE;
        }

        try {
            $summary = $service->run($directory);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('BT-03E HISTORICAL FORWARD SCORING PASS');
        $this->line('source=run:6/WF_2023/OPERATIONAL');
        $this->line('base_step='.$summary['chosen_candidate']['base_step']);
        $this->line('weights='.json_encode($summary['chosen_candidate']['weights'], JSON_THROW_ON_ERROR));
        $this->line('training_races='.$summary['training_2023']['race_count']);
        $this->line('evaluation_races='.$summary['evaluation_2024']['race_count']);
        $this->line('training_position_hit_at_3='.$summary['training_2023']['metrics']['POSITION_HIT_RATE_AT_3']);
        $this->line('evaluation_position_hit_at_3='.$summary['evaluation_2024']['point_engine_metrics']['POSITION_HIT_RATE_AT_3']);
        $this->line('bundle='.$summary['artifacts']['bundle_directory']);
        $this->line('json='.$summary['artifacts']['json']);
        $this->line('csv='.$summary['artifacts']['csv']);
        $audit = $summary['audit'];
        $this->line(sprintf(
            'DB writes=%d; 2025 access=%d; 2026 access=%d',
            $audit['executed_write_query_count'],
            $this->yearAccessCount($audit, 2025),
            $this->yearAccessCount($audit, 2026),
        ));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $audit */
    private function yearAccessCount(array $audit, int $year): int
    {
        return (int) ($audit['forbidden_year_query_or_binding_count'][$year] ?? 0)
            + (int) ($audit['snapshot_partition_access'][$year] ?? 0)
            + (int) ($audit['feature_source_access'][$year] ?? 0);
    }
}
