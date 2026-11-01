<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;

final class ResultMatcher
{
    public function match(array $candidate, array $result): array
    {
        BundleRules::require($result['external_race_key'] === $candidate['external_race_key'] && $result['original_race_id'] === $candidate['original_race_id']
            && $result['race_date'] === $candidate['race_date'] && RaceResultStatus::tryFrom($result['official_status']) !== null, 'Result race/page identity mismatch.');
        Scope::instant($result['fetched_at']);
        Scope::instant($result['saved_at']);
        BundleRules::require(Scope::instant($result['fetched_at']) <= Scope::instant($result['saved_at']), 'Result save precedes fetch.');
        if ($result['official_status'] === 'CANCELLED' && $result['entries'] === []) {
            return ['verified' => true, 'reason' => 'VERIFIED_CANCELLED_EMPTY_ROSTER'];
        }
        BundleRules::require(count($candidate['entries']) === count($result['entries']), 'Result entrant set mismatch.');
        $seen = [];
        foreach ($candidate['entries'] as $e) {
            $matches = array_values(array_filter($result['entries'], fn (array $row): bool => $row['bike'] === $e['bike']));
            BundleRules::require(count($matches) === 1 && ! isset($seen[$e['bike']]), 'Duplicate/missing result identity.');
            $seen[$e['bike']] = true;
            $actual = $matches[0];
            foreach (['original_entry_id', 'original_player_id', 'external_player_id'] as $field) {
                if ($e[$field] === null) {
                    return ['verified' => false, 'reason' => 'UNRESOLVED_IDENTITY'];
                }
                BundleRules::require($e[$field] === $actual[$field], 'Result participant identity mismatch.');
            }
        }

        return ['verified' => true, 'reason' => null];
    }
}
