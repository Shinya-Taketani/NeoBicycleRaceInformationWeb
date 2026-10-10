<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionResult;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as Request;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class Contract
{
    public const VERSION = 'C1-STAT35-COMPOSITION-RESULT-01-v1';

    public const REQUEST_STORE = Model::ROOT.'/request-store-01-pr94-validation-fix-20261010-064120-1bb292ad';

    public const RACES = [37750, 12542, 12543, 12544, 12545, 12546, 12547, 12548, 12549, 12550];

    public const LABELS = '/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/run-01/labels-2025.jsonl';

    public const LABEL_SEAL = ['rows' => 24866, 'bytes' => 72086838, 'sha256' => '509cf6e4c823c07f73f11cbae31a6844a376ced6b48b4e09d3f750ea4058beca'];

    public const FILES = ['request.json', 'sources.json', 'code.json', 'freeze.json', 'source-end.json',
        'fixed.jsonl', 'fixed.jsonl.manifest.json', 'results.jsonl', 'results.jsonl.manifest.json',
        'joined.jsonl', 'joined.jsonl.manifest.json', 'contributions.jsonl', 'contributions.jsonl.manifest.json', 'summary.json'];

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'purpose' => 'IN_SAMPLE_REPLAY_TECHNICAL_CHECK', 'years' => [2025],
            'race_ids' => self::RACES, 'historical_as_of_available' => false, 'generalization_performance_evaluated' => false,
            'formal_adoption' => false, 'formal_freeze' => false, 'live_use_authorized' => false,
            '2026_access' => 'FORBIDDEN', 'points' => null, 'gate_ci_bootstrap' => 'NOT_RUN',
            'training' => 'NONE', 'inference' => 'NONE', 'database' => 'NONE',
            'metrics' => 'EXISTING_MATCHER_AND_BT03E05_EVALUATOR'];
    }

    public static function code(): array
    {
        $paths = [...glob(__DIR__.'/*.php'), base_path('app/Console/Commands/Keirin/CompositionPredictionResultCommand.php')];
        $paths = [...$paths, ...array_map(fn (string $p): string => base_path($p), array_keys(Request::code()))];
        // Include the interpretation, baseline tie definition and saved-value verification dependencies.
        foreach ([
            'Experiments/TacticalPredictionResult/Matcher.php', 'Experiments/TacticalPredictionResult/Contract.php',
            'Calculators/Bt03e05MetricEvaluator.php', 'Services/Bt03e02Contract.php',
            'Experiments/TacticalHistory/JsonlArtifact.php', 'Experiments/TacticalHistoryFinal/Files.php',
            'Experiments/C1Stat35CompositionFinal/Publication.php', 'Experiments/C1Stat35CompositionFinal/Input.php',
            'Experiments/C1Stat35CompositionFinal/Contract.php', 'Experiments/C1Stat35P1Composition/ProbabilityCalculator.php',
            'Experiments/C1Stat35P1Composition/Reader.php',
            'Experiments/C1Stat35P1Composition/Contract.php', 'Experiments/Stat35C1Comparison/Contract.php',
            'Calculators/Bt03e03ProbabilityScorer.php', 'Calculators/Bt03e03CompensatedSum.php',
            'Calculators/Bt03e06WinnerConditionedDecoder.php', 'Services/Bt03e03Contract.php', 'Services/Bt03e06Contract.php',
            'Services/Bt03e05Contract.php', 'Support/CanonicalHasher.php',
        ] as $name) {
            $paths[] = app_path('Domain/Keirin/Backtest/'.$name);
        }
        foreach (['app/Domain/Keirin/Statistics/AgariC1Input/Validator.php',
            'app/Domain/Keirin/Statistics/AgariC1Input/Contract.php',
            'app/Domain/Keirin/Scraping/Enums/RaceEntryResultStatus.php',
            'app/Console/Commands/Keirin/FinalC1Stat35CompositionCommand.php', 'composer.lock'] as $name) {
            $paths[] = base_path($name);
        }
        $code = [];
        foreach (array_unique($paths) as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($code, SORT_STRING);

        return ['php' => PHP_VERSION, 'files' => $code];
    }
}
