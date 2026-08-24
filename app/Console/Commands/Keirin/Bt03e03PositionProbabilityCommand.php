<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e03DevelopmentEvaluationService;
use Illuminate\Console\Command;
use Throwable;

final class Bt03e03PositionProbabilityCommand extends Command
{
    protected $signature = 'keirin:backtest:bt03e03
        {--plan : Display the frozen implementation contract without fitting}
        {--execute : Execute the 2022-2025 development evaluation}
        {--verify-reproducibility= : Previous BT-03E-03 result.json for deterministic verification}
        {--output-dir=/tmp : Non-Git directory for immutable execution artifacts}';

    protected $description = 'Plan or execute the leakage-safe BT-03E-03 position probability evaluation.';

    public function handle(Bt03e03DevelopmentEvaluationService $service): int
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
            $contract = Bt03e03Contract::plan();
            $this->info('BT-03E-03 PLAN');
            $this->line('positions='.implode(',', $contract['positions']));
            $this->line('incremental_stats='.implode(',', $contract['incremental_stats']));
            $this->line('objective='.$contract['objective']);
            $this->line('probability='.$contract['probability']);
            $this->line('ranking='.$contract['ranking']);
            $this->line('lambda_selection='.$contract['lambda_selection']);
            $this->line('alpha_combination='.$contract['alpha_combination']);
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

        $this->info('BT-03E-03 DEVELOPMENT EVALUATION COMPLETE');
        foreach ([2024, 2025] as $year) {
            $metrics = $result["outer_{$year}"]['metrics'];
            foreach ([
                'WIN' => 'WINNER_HIT_AT_1',
                'P2' => 'POSITION_2_ACCURACY',
                'P3' => 'POSITION_3_ACCURACY',
                'Hit@3' => 'POSITION_HIT_RATE_AT_3',
                '3連単' => 'EXACT_ORDERED_TOP3_RATE',
                '3連複' => 'EXACT_TOP3_SET_RATE',
            ] as $label => $metric) {
                $this->line(sprintf(
                    '%d %s baseline=%.8f candidate=%.8f delta=%+.8f',
                    $year,
                    $label,
                    $metrics['baseline'][$metric],
                    $metrics['candidate'][$metric],
                    $metrics['delta'][$metric],
                ));
            }
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
