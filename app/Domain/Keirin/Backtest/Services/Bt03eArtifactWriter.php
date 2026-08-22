<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt03eBinRuleDto;
use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use RuntimeException;

class Bt03eArtifactWriter
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  list<Bt03eBinRuleDto>  $rules
     * @return array{json: string, csv: string}
     */
    public function write(string $directory, array $summary, array $rules, Bt03eCandidateDto $candidate): array
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the BT-03E artifact directory.');
        }
        $prefix = rtrim($directory, '/').'/bt03e-historical-forward-scoring-'.gmdate('Ymd-His');
        $jsonPath = $prefix.'.json';
        $csvPath = $prefix.'.csv';
        $json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (file_put_contents($jsonPath, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('Could not write the BT-03E JSON artifact.');
        }

        $handle = fopen($csvPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Could not create the BT-03E CSV artifact.');
        }
        try {
            fputcsv($handle, ['stat_code', 'bin_index', 'bin_origin', 'bin_kind', 'lower_bound', 'upper_bound', 'category_value', 'direction_strength', 'stat_weight', 'final_points', 'source_fold', 'source_effect_bin_id', 'source_boundaries_hash']);
            fputcsv($handle, ['STAT-01', '', 'BASE_RANK', 'BASE_RANK', '', '', '', '', '', $candidate->baseStep, Bt03eContract::SOURCE_FOLD, '', '']);
            foreach ($rules as $rule) {
                $weight = $candidate->weights[$rule->statCode];
                fputcsv($handle, [
                    $rule->statCode, $rule->binIndex, $rule->binOrigin, $rule->binKind, $rule->lowerBound,
                    $rule->upperBound, $rule->categoryValue, $rule->directionStrength,
                    $weight, $rule->directionStrength * $weight, Bt03eContract::SOURCE_FOLD,
                    $rule->sourceEffectBinId, $rule->sourceBoundariesHash,
                ]);
            }
        } finally {
            fclose($handle);
        }

        return ['json' => $jsonPath, 'csv' => $csvPath];
    }
}
