<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat36C1Comparison;

use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Contract as Candidate;
use RuntimeException;

final class CandidateRow
{
    public static function project(array $row, int $year): array
    {
        C1::keys($row, ['year', 'race_id', 'entry_id', 'bike', 'race_date', 'source', 'external_player_id',
            'candidate_displayed_start_count', 'state', 'aggregation_period', 'statistical_as_of', 'correction_as_of',
            'timing_status', ...array_keys(Candidate::restrictions())]);
        $value = $row['candidate_displayed_start_count'];
        if ($row['year'] !== $year || ! C1::id($row['race_id']) || ! C1::id($row['entry_id'])
            || ! C1::id($row['bike']) || $row['bike'] > 9 || ! C1::date($row['race_date'])
            || (int) substr($row['race_date'], 0, 4) !== $year || $row['source'] !== 'keirin_jp'
            || ! is_string($row['external_player_id']) || ! preg_match('/\A[0-9]{6}\z/D', $row['external_player_id'])
            || ($value !== null && (! is_int($value) || $value < 0 || $value > 9999))
            || ! in_array($row['state'], ['UNIQUE_DISPLAY_VALUE', 'VALUE_CONFLICT', 'IDENTITY_OR_CONTEXT_UNVERIFIED',
                'NO_MATCHING_SNAPSHOT', 'INCOMPLETE_OR_CONTRADICTORY_VERSIONS'], true)
            || (($row['state'] === 'UNIQUE_DISPLAY_VALUE') !== ($value !== null))
            || $row['aggregation_period'] !== null || $row['statistical_as_of'] !== null || $row['correction_as_of'] !== null
            || $row['timing_status'] !== 'UNKNOWN_S_PERIOD_BASELINE_CORRECTION') {
            throw new RuntimeException('Invalid S candidate identity/value/state.');
        }
        foreach (Candidate::restrictions() as $key => $expected) {
            if ($row[$key] !== $expected) {
                throw new RuntimeException('Original S candidate restrictions changed.');
            }
        }

        // Metadata is checked, never passed to the learner.
        return ['year' => $row['year'], 'race_id' => $row['race_id'], 'entry_id' => $row['entry_id'],
            'bike' => $row['bike'], 'value' => $value];
    }
}
