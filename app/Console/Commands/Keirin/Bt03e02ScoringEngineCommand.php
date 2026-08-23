<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e02DevelopmentEvaluationService;
use Illuminate\Console\Command;
use Throwable;

final class Bt03e02ScoringEngineCommand extends Command
{
    protected $signature = 'keirin:backtest:bt03e02
        {--plan : Display the frozen implementation contract without fitting}
        {--execute : Execute the 2022-2025 development evaluation}
        {--verify-reproducibility= : Previous BT-03E-02 result.json to verify a deterministic rerun}
        {--output-dir=/tmp : Non-Git directory for immutable execution artifacts}';

    protected $description = 'Plan or execute the leakage-safe BT-03E-02 development scoring evaluation.';

    public function handle(Bt03e02DevelopmentEvaluationService $service): int
    {
        $plan = (bool) $this->option('plan');
        $execute = (bool) $this->option('execute');
        $verification = $this->option('verify-reproducibility');
        if ($plan === $execute) {
            $this->error('Specify exactly one of --plan or --execute.');

            return self::FAILURE;
        }
        if ($plan && $verification !== null) {
            $this->error('--verify-reproducibility is valid only with --execute.');

            return self::FAILURE;
        }
        if ($plan) {
            $contract = Bt03e02Contract::plan();
            $this->info('BT-03E-02 PLAN');
            $this->line('incremental_stats='.implode(',', $contract['incremental_stats']));
            $this->line('cohort='.$contract['cohort']);
            $this->line('outer_2024='.$contract['outer_folds']['2024']);
            $this->line('outer_2025='.$contract['outer_folds']['2025']);
            $this->line('lambda_grid='.implode(',', $contract['lambda_grid']));
            $this->line('alpha_candidates='.$contract['alpha_candidate_count']);
            $this->line('optimizer='.$contract['optimizer_version']);
            $this->line('2026_access='.$contract['2026_access']);
            $this->line('read_only=true');
            $this->line('memory='.$contract['memory_limit_contract']);

            return self::SUCCESS;
        }

        $directory = $this->option('output-dir');
        if (! is_string($directory) || $directory === '') {
            $this->error('--output-dir must be a non-empty path.');

            return self::FAILURE;
        }
        try {
            $result = $service->run($directory, is_string($verification) && $verification !== '' ? $verification : null);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('BT-03E-02 DEVELOPMENT EVALUATION COMPLETE');
        foreach ([2024, 2025] as $year) {
            $metrics = $result["outer_{$year}"]['metrics'];
            $this->line(sprintf(
                '%d 単勝 baseline=%.8f candidate=%.8f delta=%+.8f',
                $year,
                $metrics['baseline']['WINNER_HIT_AT_1'],
                $metrics['candidate']['WINNER_HIT_AT_1'],
                $metrics['delta']['WINNER_HIT_AT_1'],
            ));
            $this->line(sprintf(
                '%d 3連単 baseline=%.8f candidate=%.8f delta=%+.8f',
                $year,
                $metrics['baseline']['EXACT_ORDERED_TOP3_RATE'],
                $metrics['candidate']['EXACT_ORDERED_TOP3_RATE'],
                $metrics['delta']['EXACT_ORDERED_TOP3_RATE'],
            ));
            $this->line(sprintf(
                '%d 3連複 baseline=%.8f candidate=%.8f delta=%+.8f',
                $year,
                $metrics['baseline']['EXACT_TOP3_SET_RATE'],
                $metrics['candidate']['EXACT_TOP3_SET_RATE'],
                $metrics['delta']['EXACT_TOP3_SET_RATE'],
            ));
        }
        foreach ($result['acceptance_gate']['gates'] as $gate => $passed) {
            $this->line("gate_{$gate}=".($passed ? 'PASS' : 'FAIL'));
        }
        $this->line('status='.$result['acceptance_gate']['status']);
        $this->line('reproducibility='.$result['reproducibility_verification']['status']);
        $this->line('2026_access='.$result['audit']['2026_access_count']);
        $this->line('bundle='.$result['artifacts']['bundle_directory']);

        return self::SUCCESS;
    }
}
