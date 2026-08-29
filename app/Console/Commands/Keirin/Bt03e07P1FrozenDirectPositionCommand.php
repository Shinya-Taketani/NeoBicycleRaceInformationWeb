<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e07DevelopmentEvaluationService;
use Illuminate\Console\Command;
use Throwable;

final class Bt03e07P1FrozenDirectPositionCommand extends Command
{
    protected $signature = 'keirin:backtest:bt03e07
        {--plan : Display the frozen P1/direct-position contract without source or database access}
        {--execute : Execute the 2024/2025 development evaluation}
        {--source-bundle= : Verified BT-03E-03 v2 artifact bundle}
        {--verify-reproducibility= : Previous BT-03E-07 result.json}
        {--output-dir=/tmp : Non-Git directory for immutable execution artifacts}';

    protected $description = 'Plan or execute the BT-03E-07 P1-frozen direct P2/P3 evaluation.';

    public function handle(Bt03e07DevelopmentEvaluationService $service): int
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
            $contract = Bt03e07Contract::plan();
            $this->info('BT-03E-07 PLAN');
            foreach (['contract', 'calculation_version', 'objective_version', 'optimizer_version', 'lambda_selection_version', 'probability_version', 'decoder_version', 'p1_freeze_rule', 'p2_direct_formula', 'p3_direct_formula', 'pair_objective', 'primary_tie_rule_version', 'supporting_tie_rule_version'] as $key) {
                $this->line("{$key}=".$contract[$key]);
            }
            $this->line('source_e03_versions='.json_encode($contract['source_model_contract'], JSON_THROW_ON_ERROR));
            $this->line('fold_structure='.json_encode($contract['outer_folds'], JSON_THROW_ON_ERROR));
            $this->line('lambda_grid='.json_encode($contract['lambda_grid'], JSON_THROW_ON_ERROR));
            $this->line('fista_constants='.json_encode($contract['solver_constants'], JSON_THROW_ON_ERROR));
            $this->line('bootstrap='.json_encode($contract['bootstrap'], JSON_THROW_ON_ERROR));
            $this->line('gate='.json_encode($contract['acceptance_gate'], JSON_THROW_ON_ERROR));
            $this->line('development_years='.implode(',', $contract['development_years']));
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
            $result = $service->run($sourceBundle, $directory, is_string($verification) && $verification !== '' ? $verification : null);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
        $this->info('BT-03E-07 DEVELOPMENT EVALUATION COMPLETE');
        foreach (Bt03e07Contract::OUTER_YEARS as $year) {
            foreach (['WIN' => 'WINNER_HIT_AT_1', 'P2' => 'POSITION_2_ACCURACY', 'P3' => 'POSITION_3_ACCURACY', 'Hit@3' => 'POSITION_HIT_RATE_AT_3'] as $label => $metric) {
                $values = $result["outer_{$year}"]['metrics'];
                $this->line(sprintf('%d %s baseline=%.8f candidate=%.8f delta=%+.8f', $year, $label, $values['baseline'][$metric], $values['candidate'][$metric], $values['delta'][$metric]));
            }
        }
        $this->line('status='.$result['acceptance_gate']['status']);
        $this->line('reproducibility='.$result['reproducibility_verification']['status']);
        $this->line('2026_access='.$result['audit']['2026_access_count']);
        $this->line('bundle='.$result['artifacts']['bundle_directory']);

        return self::SUCCESS;
    }
}
