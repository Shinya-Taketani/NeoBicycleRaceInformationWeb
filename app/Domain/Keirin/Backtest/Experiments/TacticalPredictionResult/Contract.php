<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult;

use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Contract as PredictionContract;
use RuntimeException;

final class Contract
{
    public static function plan(): array
    {
        return ['version' => 'TACTICAL-PREDICTION-RESULT-01-v1', 'mode' => PredictionContract::MODE,
            'purpose' => 'IN_SAMPLE_REPLAY_TECHNICAL_CHECK', 'years' => [2022, 2023, 2024, 2025],
            'model_sha256' => PredictionContract::MODEL_SHA256,
            'metrics' => 'Bt03e05MetricEvaluator::raceComparison/add/finish',
            'database' => 'NONE', 'inference' => 'NONE', 'training' => 'NONE',
            'gate_ci_bootstrap' => 'NOT_PERMITTED', 'holdout_2026' => 'FORBIDDEN'];
    }

    public static function mode(string $mode): void
    {
        if ($mode !== PredictionContract::MODE) {
            throw new RuntimeException('Only DEVELOPMENT_REPLAY_ONLY is permitted.');
        }
    }

    public static function id(string $id): void
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}\z/', $id)) {
            throw new RuntimeException('Invalid evaluation/request ID.');
        }
    }

    public static function race(array $row): void
    {
        if (! in_array($row['year'] ?? null, self::plan()['years'], true)
            || ! is_int($row['race_id'] ?? null) || $row['race_id'] < 1) {
            throw new RuntimeException('Invalid or forbidden year/race identity.');
        }
    }
}
