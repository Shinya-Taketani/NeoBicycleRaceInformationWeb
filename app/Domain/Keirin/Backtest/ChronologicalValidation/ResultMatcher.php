<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;

final class ResultMatcher
{
    public function match(array $candidate, array $result): array
    {
        Scope::day($result['race_date']);
        BundleRules::require($result['external_race_key'] === $candidate['external_race_key']
            && $result['race_date'] === $candidate['race_date'] && RaceResultStatus::tryFrom($result['official_status']) !== null, 'Result race/page identity mismatch.');
        $unresolved = false;
        foreach (['original_race_id'] as $field) {
            foreach ([$candidate[$field], $result[$field]] as $id) {
                BundleRules::require($id === null || (is_int($id) && $id > 0), 'Result race/page identity mismatch.');
            }
            $unresolved = $candidate[$field] === null || $result[$field] === null;
            BundleRules::require($unresolved || $candidate[$field] === $result[$field], 'Result race/page identity mismatch.');
        }
        Scope::instant($result['fetched_at']);
        Scope::instant($result['saved_at']);
        BundleRules::require(Scope::instant($result['fetched_at']) <= Scope::instant($result['saved_at']), 'Result save precedes fetch.');
        foreach ($result['entries'] as $actual) {
            foreach (['original_entry_id', 'original_player_id'] as $field) {
                BundleRules::require($actual[$field] === null || (is_int($actual[$field]) && $actual[$field] > 0), 'Result participant identity mismatch.');
            }
            BundleRules::require(RaceEntryResultStatus::tryFrom($actual['status']) !== null, 'Unknown official result status.');
            BundleRules::require(in_array($actual['status'], ['FINISHED', 'TIED'], true)
                ? is_int($actual['rank']) && $actual['rank'] > 0 && $actual['rank'] <= count($result['entries']) : $actual['rank'] === null, 'Invalid official rank.');
        }
        if ($result['official_status'] === 'CANCELLED' && $result['entries'] === []) {
            return ['verified' => ! $unresolved, 'reason' => $unresolved ? 'UNRESOLVED_IDENTITY' : 'VERIFIED_CANCELLED_EMPTY_ROSTER'];
        }
        BundleRules::require(count($candidate['entries']) === count($result['entries']), 'Result entrant set mismatch.');
        $seen = [];
        foreach ($candidate['entries'] as $e) {
            $matches = array_values(array_filter($result['entries'], fn (array $row): bool => $row['bike'] === $e['bike']));
            BundleRules::require(count($matches) === 1 && ! isset($seen[$e['bike']]), 'Duplicate/missing result identity.');
            $seen[$e['bike']] = true;
            $actual = $matches[0];
            foreach (['original_entry_id', 'original_player_id', 'external_player_id'] as $field) {
                if ($e[$field] === null || $actual[$field] === null) {
                    $unresolved = true;
                } else {
                    BundleRules::require($e[$field] === $actual[$field], 'Result participant identity mismatch.');
                }
            }
            BundleRules::require(RaceEntryResultStatus::tryFrom($actual['status']) !== null, 'Unknown official result status.');
        }

        return ['verified' => ! $unresolved, 'reason' => $unresolved ? 'UNRESOLVED_IDENTITY' : null];
    }
}
