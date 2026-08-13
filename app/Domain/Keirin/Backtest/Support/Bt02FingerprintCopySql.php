<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use InvalidArgumentException;

class Bt02FingerprintCopySql
{
    public function for(int $runId, Bt02FingerprintType $type): string
    {
        if ($runId < 1) {
            throw new InvalidArgumentException('BT-02 fingerprint run ID must be positive.');
        }
        $contentColumns = $type === Bt02FingerprintType::Content
            ? ",\n        features::text,\n        evidence::text,\n        raw_points::text,\n        confidence::text,\n        effective_points::text"
            : '';

        $template = <<<'SQL'
COPY (
    SELECT
        feature_run_id,
        stat_code,
        calculation_version,
        subject_type,
        subject_key,
        race_id,
        race_entry_id,
        player_id,
        status,
        quality_status,
        input_hash{content_columns}
    FROM statistic_feature_results
    WHERE feature_run_id = {run_id}
    ORDER BY
        race_id ASC NULLS FIRST,
        race_entry_id ASC NULLS FIRST,
        player_id ASC NULLS FIRST,
        id ASC
) TO STDOUT WITH (
    FORMAT CSV,
    DELIMITER ',',
    NULL '\N',
    QUOTE E'\x22',
    ESCAPE E'\x22',
    HEADER FALSE,
    ENCODING 'UTF8'
);
SQL;

        return str_replace(
            ['{content_columns}', '{run_id}'],
            [$contentColumns, (string) $runId],
            $template,
        );
    }
}
