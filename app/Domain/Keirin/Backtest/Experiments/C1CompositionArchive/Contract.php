<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as Requests;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Contract as Results;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class Contract
{
    public const VERSION = 'C1-STAT35-COMPOSITION-ARCHIVE-01-v1';

    public const PAGE_SIZE = 100;

    public static function sources(): array
    {
        return [
            'predictions' => ['path' => Model::ROOT.'/pr93-repackage-commit-fix-20261009-e91a7f3c/predictions-2025/predictions.jsonl',
                'seal' => ['rows' => 24866, 'bytes' => 218137295, 'sha256' => 'cab188fad614e49c0d699fab38b71ea57ce3e902fc15d5aa2cfadd22cad23e23']],
            'input' => ['path' => Model::ROOT.'/run-20261009-LE6Wit1O/result/verified-inputs/features-2025.jsonl',
                'seal' => ['rows' => 24866, 'bytes' => 72144103, 'sha256' => '4bf8c8ccd61125e2562807154b4cffafcb6fcd2985521206e59cfc9df0fa6bfa']],
            'labels' => ['path' => '/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/run-01/labels-2025.jsonl',
                'seal' => ['rows' => 24866, 'bytes' => 72086838, 'sha256' => '509cf6e4c823c07f73f11cbae31a6844a376ced6b48b4e09d3f750ea4058beca']],
        ];
    }

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'year' => 2025, 'page_size' => self::PAGE_SIZE,
            'order' => 'SAVED_PREDICTION_RACE_AND_ENTRANT_ORDER', 'sources' => self::sources(),
            'purpose' => 'IN_SAMPLE_SAVED_PREDICTION_ARCHIVE', 'historical_as_of_available' => false,
            'generalization_performance_evaluated' => false, 'formal_adoption' => false, 'formal_freeze' => false,
            'live_use_authorized' => false, '2026_access' => 'FORBIDDEN', 'gate_ci_bootstrap' => 'NOT_RUN', 'points' => null];
    }

    public static function code(): array
    {
        // Viewer pins are intentionally outside the generating code identity.
        $files = Model::code() + Requests::code() + Results::code()['files'];
        foreach (glob(__DIR__.'/*.php') as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        $command = 'app/Console/Commands/Keirin/CompositionPredictionArchiveCommand.php';
        $files[$command] = Files::identity(base_path($command));
        ksort($files, SORT_STRING);

        return ['php' => PHP_VERSION, 'files' => $files];
    }
}
