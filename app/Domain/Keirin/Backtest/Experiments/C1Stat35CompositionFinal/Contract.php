<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract as Composition;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract as C2;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;

final class Contract
{
    public const VERSION = 'C1-STAT35-COMPOSITION-FINAL-01-v1';

    public const INPUT_VERSION = 'C1-STAT35-COMPOSITION-FEATURE-INPUT-v1';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01';

    public const C1 = '/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01/fit/run-01/final/artifact.json';

    public const C1_SHA = 'e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1';

    public const REFERENCE = '/home/shinya/neo-keirin-artifacts/c1-stat35-p1-composition-01/run-20261008-fALiXmYk/result';

    public const REFERENCE_SHA = 'f1c1687aed2aa6cf9540c6f351e8017c240f096177e3206af775d936e3d92264';

    public const OMITTED = 'INELIGIBLE_FROM_REUSED_FOLDS_NOT_FIT_OOF3';

    public static function plan(): array
    {
        $prior = Composition::plan();

        return ['version' => self::VERSION, 'input_version' => self::INPUT_VERSION,
            'model_format' => C2::MODEL_VERSION, 'model_format_origin' => C2::VERSION,
            'generation_phase' => 'C1-STAT35-COMPOSITION-FINAL-01',
            'position_sources' => ['POSITION_1' => 'FINAL_C2', 'POSITION_2' => 'EXISTING_FINAL_C1', 'POSITION_3' => 'EXISTING_FINAL_C1'],
            'training_years' => [2022, 2023, 2024, 2025], 'prediction_years' => [2024, 2025],
            'features' => C2::features(), 'lambda_grid' => Bt03e03Contract::LAMBDA_GRID,
            'fit_execution_order' => Bt03e03Contract::FIT_EXECUTION_ORDER,
            'selection' => 'EXISTING_ONE_SE_THREE_VALIDATION_YEARS_THREE_POSITIONS_EQUAL',
            'bootstrap_iterations' => Bt03e03Contract::BOOTSTRAP_ITERATIONS, 'bootstrap_seed' => Bt03e03Contract::BOOTSTRAP_SEED,
            'c1_retraining_count' => 0, 'old_oof_retraining_count' => 0, 'new_fit_paths_maximum' => 4,
            'probability' => Composition::CALCULATION, 'decoder' => $prior['decoder'],
            'primary_tie' => $prior['primary_tie'], 'supporting_tie' => $prior['supporting_tie'],
            'performance_evaluation' => 'NOT_PERFORMED_FINAL_FIT_AND_TECHNICAL_REPRODUCTION_ONLY',
            'use_restrictions' => ['purpose' => 'DEVELOPMENT_FINAL_MODEL_CANDIDATE_ONLY', 'historical_as_of_available' => false,
                'formal_adoption' => false, 'formal_freeze' => false, 'live_use_authorized' => false, '2026_access' => 'FORBIDDEN', 'points' => null]];
    }

    public static function code(): array
    {
        $files = Composition::code();
        foreach (['C1Stat35CompositionFinal', 'Stat35C1Comparison', 'TacticalHistoryFinal'] as $name) {
            foreach (glob(app_path('Domain/Keirin/Backtest/Experiments/'.$name.'/*.php')) as $path) {
                $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
            }
        }
        foreach (['Calculators/Bt03e03OneSeSelector.php', 'Support/Bt03e03ValidationLossSpool.php',
            'Calculators/Bt03e02CompensatedSum.php', 'Calculators/EffectBinBuilder.php', 'DTO/EffectBinDto.php',
            'Calculators/ExternalSortEffectBinBoundaryProvider.php', 'Contracts/EffectBinBoundaryProvider.php',
            'Support/BoundedProcessRunner.php', 'DTO/BoundedProcessResultDto.php',
            'Experiments/TacticalHistory/Layout.php', 'Experiments/TacticalHistory/Predictor.php', 'Experiments/TacticalHistory/Dataset.php'] as $name) {
            $path = app_path('Domain/Keirin/Backtest/'.$name);
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        foreach (['app/Domain/Keirin/Statistics/AgariC1Input/SourceProjector.php', 'app/Providers/AppServiceProvider.php',
            'bootstrap/app.php', 'bootstrap/providers.php', 'vendor/composer/installed.php'] as $name) {
            $files[$name] = Files::identity(base_path($name));
        }
        foreach (glob(app_path('Console/Commands/Keirin/*C1Stat35Composition*.php')) as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($files, SORT_STRING);

        return $files;
    }
}
