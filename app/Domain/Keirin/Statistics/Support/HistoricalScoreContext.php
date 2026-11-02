<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

final class HistoricalScoreContext
{
    public static function summarize(int $entrantCount, array $entries, StatisticalMath $math): array
    {
        $complete = count($entries) === $entrantCount;
        $scores = [];
        foreach ($entries as $entry) {
            $value = $entry['race_score'];
            if ($value === null || ! is_finite((float) $value) || (float) $value <= 0) {
                $complete = false;
            } else {
                $scores[$entry['race_entry_id']] = (float) $value;
            }
        }
        $percentiles = array_fill_keys(array_column($entries, 'race_entry_id'), null);
        if ($complete && count($scores) > 1) {
            foreach ($scores as $id => $value) {
                $rank = 1 + count(array_filter($scores, fn (float $score): bool => $score > $value));
                $percentiles[$id] = (count($scores) - $rank) / (count($scores) - 1);
            }
        }
        $numeric = $complete ? array_values($scores) : [];

        return ['percentiles' => $percentiles, 'score_mean' => $math->mean($numeric), 'score_max' => $numeric === [] ? null : max($numeric),
            'score_stddev_pop' => $math->populationStandardDeviation($numeric)];
    }
}
