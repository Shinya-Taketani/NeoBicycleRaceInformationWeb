<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as ModelContract;

final class Contract
{
    public const VERSION = 'TACTICAL-PREDICTION-PIPELINE-01-v1';

    public const MODE = 'DEVELOPMENT_REPLAY_ONLY';

    public const MODEL_SHA256 = 'e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1';

    public static function plan(): array
    {
        return ['pipeline_version' => self::VERSION, 'mode' => self::MODE, 'years' => [2022, 2023, 2024, 2025],
            'model_sha256' => self::MODEL_SHA256, 'model_contract' => ModelContract::plan(),
            'scope' => 'BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY', 'publication_time_verified' => 'UNKNOWN',
            'cutoff' => 'SALES_CLOSE_AT_ELSE_SCHEDULED_START_AT_FALLBACK', 'database' => 'READ_ONLY',
            'source_check' => 'TARGET_METADATA_FIXED_RUN_IDENTITY_TARGET_STAT_ROWS_AND_120D_HISTORY_START_END',
            'publication' => 'STAGING_THEN_ATOMIC_REQUEST_DIRECTORY_RENAME', 'success' => 'DEVELOPMENT_REPLAY_LOCKED',
            '2026_access' => 'FORBIDDEN', 'training' => 'FORBIDDEN', 'accuracy_evaluation' => 'FORBIDDEN'];
    }
}
