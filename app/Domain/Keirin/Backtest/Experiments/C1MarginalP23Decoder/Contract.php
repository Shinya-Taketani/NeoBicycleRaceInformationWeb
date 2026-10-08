<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder;

use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Contract as Fixed;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e05Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;

final class Contract
{
    public const VERSION = 'C1-MARGINAL-P23-DECODER-01-v1';

    public const CANDIDATE = 'C1_WINNER_FIXED_MARGINAL_P23';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/c1-marginal-p23-decoder-01';

    public const INPUT = Fixed::INPUT;

    public const BASELINE = Fixed::BASELINE;

    public const INPUT_SHA = Fixed::INPUT_SHA;

    public const EXPORT_SHA = Fixed::EXPORT_SHA;

    public const BASELINE_CONTRACT_SHA = Fixed::BASELINE_CONTRACT_SHA;

    public const PRIMARY = ['WINNER_HIT_AT_1', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY', 'POSITION_HIT_RATE_AT_3'];

    public static function plan(): array
    {
        return ['experiment' => self::VERSION, 'candidate' => self::CANDIDATE,
            'artifact_role' => 'FIXED_MODEL_DECISION_POLICY_COMPARISON',
            'model_version' => 'TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2',
            'source' => ['input_manifest' => self::INPUT_SHA, 'export_manifest' => self::EXPORT_SHA, 'contract' => self::BASELINE_CONTRACT_SHA],
            'years' => [2024, 2025], 'training_count' => 0, 'independent_decode_evaluation_runs' => 2,
            'candidate_decoder' => Bt03e05Contract::DECODER_VERSION,
            'baseline_decoder' => Bt03e06Contract::DECODER_VERSION,
            'primary_tie' => Bt03e05Contract::TIE_RULE_VERSION,
            'objective' => 'MAX_P2_PLUS_P3_DISTINCT_NON_WINNER_ORDERED_PAIR',
            'label_release' => 'AFTER_BOTH_YEARS_DECISION_SEAL_VERIFIED',
            'bootstrap' => ['iterations' => 2000, 'seed' => 20260812, 'quantile' => 'TYPE7', 'unit' => 'YEAR_STRATIFIED_PAIRED_RACE', 'aggregation' => 'YEAR_EQUAL'],
            'incremental_gate' => 'UNCHANGED_TACTICAL_HISTORY_INCREMENTAL_GATE',
            'use_restrictions' => ['scope' => 'LIMITED_DEVELOPMENT_EXPERIMENT_ONLY', 'historical_as_of_available' => false,
                'formal_adoption' => false, 'live_use_authorized' => false, '2026_access' => 'FORBIDDEN', 'points' => null]];
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        $dependencies = [
            'Backtest/Calculators/Bt03e05DecisionDecoder.php', 'Backtest/Calculators/Bt03e06WinnerConditionedDecoder.php',
            'Backtest/Calculators/Bt03e03ProbabilityScorer.php', 'Backtest/Calculators/Bt03e03CompensatedSum.php',
            'Backtest/Calculators/Bt03e05MetricEvaluator.php', 'Backtest/Calculators/Bt03e05PairedBootstrap.php',
            'Backtest/Calculators/Bt03e05AcceptanceGate.php', 'Backtest/Calculators/Type7Quantile.php', 'Backtest/Calculators/DeterministicRandom.php',
            'Backtest/Support/CanonicalHasher.php', 'Backtest/Support/Bt03e05MetricContributionSpool.php',
            'Backtest/Experiments/TacticalHistory/Evaluation.php', 'Backtest/Experiments/TacticalHistory/JsonlArtifact.php',
            'Backtest/Experiments/TacticalHistory/SolverContract.php', 'Backtest/Experiments/TacticalHistory/HistoryAggregator.php',
            'Backtest/Experiments/TacticalHistoryFinal/Files.php', 'Backtest/Experiments/TacticalHistoryFinal/Contract.php',
            'Backtest/Experiments/C1PositionLambda/Contract.php',
            'Backtest/Experiments/TacticalPredictionResult/Matcher.php', 'Backtest/Experiments/TacticalPredictionResult/Contract.php',
            'Scraping/Enums/RaceEntryResultStatus.php', 'Backtest/DTO/Bt03e03FitResultDto.php',
            'Statistics/AgariRaceRelative/Artifacts.php', 'Statistics/AgariC1Input/Contract.php', 'Statistics/AgariC1Input/Validator.php',
        ];
        foreach ($dependencies as $path) {
            $paths[] = base_path('app/Domain/Keirin/'.$path);
        }
        foreach (['02', '03', '04', '05', '06'] as $number) {
            $paths[] = base_path('app/Domain/Keirin/Backtest/Services/Bt03e'.$number.'Contract.php');
        }
        $paths[] = base_path('app/Console/Commands/Keirin/CompareC1MarginalP23Command.php');
        $paths[] = base_path('composer.lock');
        sort($paths);
        $code = [];
        foreach (array_unique($paths) as $path) {
            $code[str_replace(base_path().'/', '', $path)] = Files::identity($path);
        }

        return $code;
    }
}
