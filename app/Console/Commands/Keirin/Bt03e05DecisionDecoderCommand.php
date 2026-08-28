<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03e05Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e05DevelopmentEvaluationService;
use Illuminate\Console\Command;
use Throwable;

final class Bt03e05DecisionDecoderCommand extends Command
{
    protected $signature = 'keirin:backtest:bt03e05
        {--plan : Display the frozen winner-preserving decoder contract without source or database access}
        {--execute : Execute the 2024/2025 development redesign evaluation}
        {--source-bundle= : Verified BT-03E-03 v2 artifact bundle}
        {--verify-reproducibility= : Previous BT-03E-05 result.json}
        {--output-dir=/tmp : Non-Git directory for immutable execution artifacts}';

    protected $description = 'Plan or execute the BT-03E-05 winner-preserving lexicographic decoder evaluation.';

    public function handle(Bt03e05DevelopmentEvaluationService $service): int
    {
        $plan = (bool) $this->option('plan');
        $execute = (bool) $this->option('execute');
        $sourceBundle = $this->option('source-bundle');
        $verification = $this->option('verify-reproducibility');
        if ($plan === $execute) {
            $this->error('Specify exactly one of --plan or --execute.');

            return self::FAILURE;
        }
        if ($plan && ($sourceBundle !== null || $verification !== null)) {
            $this->error('--source-bundle and --verify-reproducibility are valid only with --execute.');

            return self::FAILURE;
        }
        if ($plan) {
            $contract = Bt03e05Contract::plan();
            $this->info('BT-03E-05 PLAN');
            $this->line('contract='.$contract['contract']);
            $this->line('calculation_version='.$contract['calculation_version']);
            $this->line('decoder_version='.$contract['decoder_version']);
            $this->line('source_calculation='.$contract['source_model_contract']['calculation_version']);
            foreach ($contract['metric_to_decoder'] as $metric => $decoder) {
                $this->line("decoder_{$metric}={$decoder}");
            }
            $this->line('tie_rule='.$contract['tie_rule']);
            $this->line('bootstrap='.json_encode($contract['bootstrap'], JSON_THROW_ON_ERROR));
            $this->line('development_years='.implode(',', $contract['development_years']));
            $this->line('model_fitting='.$contract['model_fitting']);
            $this->line('2026_access='.$contract['2026_access']);
            $this->line('read_only=true');

            return self::SUCCESS;
        }
        if (! is_string($sourceBundle) || $sourceBundle === '') {
            $this->error('--source-bundle is required with --execute.');

            return self::FAILURE;
        }
        $directory = $this->option('output-dir');
        if (! is_string($directory) || $directory === '') {
            $this->error('--output-dir must be a non-empty path.');

            return self::FAILURE;
        }
        try {
            $result = $service->run(
                $sourceBundle,
                $directory,
                is_string($verification) && $verification !== '' ? $verification : null,
            );
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
        $this->info('BT-03E-05 DEVELOPMENT EVALUATION COMPLETE');
        foreach (Bt03e05Contract::DEVELOPMENT_YEARS as $year) {
            $metrics = $result["outer_{$year}"]['metrics'];
            foreach (['WIN' => 'WINNER_HIT_AT_1', 'P2' => 'POSITION_2_ACCURACY', 'P3' => 'POSITION_3_ACCURACY', 'Hit@3' => 'POSITION_HIT_RATE_AT_3'] as $label => $metric) {
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
