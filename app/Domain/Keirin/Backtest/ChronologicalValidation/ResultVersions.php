<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

final class ResultVersions
{
    public static function save(Store $store, array $candidate, array $projection, array $source, Scope $scope, array $lock, array $receipt, ?array $parent): array
    {
        InputManifest::keys($source, ['external_race_key', 'original_race_id', 'race_date', 'result_version', 'source_id', 'official_status', 'fetched_at', 'saved_at', 'entries', 'source_seal']);
        $scope->target($source['race_date']);
        BundleRules::require(is_int($source['source_seal']['bytes'] ?? null) && $source['source_seal']['bytes'] >= 0
            && is_string($source['source_seal']['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $source['source_seal']['sha256']) === 1, 'Invalid result acquisition seal.');
        BundleRules::require(is_int($source['result_version']) && $source['result_version'] > 0 && is_string($source['source_id']) && $source['source_id'] !== '', 'Invalid result version/source identity.');
        foreach ($source['entries'] as $entry) {
            InputManifest::keys($entry, [...InputManifest::IDENTITY, 'rank', 'status', 'winning_technique']);
        }
        $audit = (new ResultMatcher)->match($candidate, $source);
        $prefix = 'd'.str_replace('-', '', $source['race_date']).'-'.substr(hash('sha256', $source['external_race_key']), 0, 12);
        $id = $prefix.'-result-v'.$source['result_version'];
        if ($store->has($id)) {
            $old = $store->get($store->reference($id))['data'];
            BundleRules::require(Json::hash($source) === Json::hash($old['source_result']) && $old['parent_result'] === $parent
                && $old['day_lock'] === $lock && $old['opening_receipt'] === $receipt, 'CONFLICT: result version content or acquisition evidence differs.');

            return $store->reference($id);
        }
        $locked = $store->get($lock)['data'];
        $row = [...$source, 'source_result' => $source, 'result_id' => $id, 'parent_result' => $parent, 'raw' => $source['source_seal'],
            'fetch_log_or_capture_id' => $source['source_id'], 'official_publication_at' => null, 'publication_reason' => 'UNKNOWN',
            'predictions' => $locked['predictions'][$source['external_race_key']], 'day_lock' => $lock, 'opening_receipt' => $receipt,
            'identity_audit' => $audit, 'parser_version' => 'CHRONOLOGICAL-OFFLINE-RESULT-v2', 'history_event' => null];
        if ($audit['verified'] && $projection['race'] !== null && $projection['race']['scheduled_start_at'] !== null
            && $projection['race']['meeting_start'] !== null && count($source['entries']) === count($projection['entries'])) {
            $entries = [];
            foreach ($projection['entries'] as $entry) {
                $actual = array_values(array_filter($source['entries'], fn (array $e): bool => $e['bike'] === $entry['bike']))[0];
                $entries[] = [...$entry, 'rank' => $actual['rank'], 'status' => $actual['status'], 'winning_technique' => $actual['winning_technique']];
            }
            $row['history_event'] = ['material_kind' => $scope->kind, 'race' => $projection['race'], 'context' => $projection['context'], 'entries' => $entries,
                'race_status' => $source['official_status'], 'source_id' => $source['source_id'], 'verified' => true,
                'logical_available_from' => Scope::day($source['race_date'])->modify('+1 day')->format('Y-m-d'),
                'input_fetched_at' => $projection['capture_refs'][0]['fetched_at'], 'fetched_at' => $source['fetched_at'], 'saved_at' => $source['saved_at'], 'confirmed_at' => null];
        }

        return $store->put($id, 'result_bundle', $row);
    }

    public static function adopt(Store $store, array $previous, array $incoming): array
    {
        $byRace = [];
        foreach ([...$previous, ...$incoming] as $ref) {
            $row = $store->get($ref)['data'];
            $key = $row['external_race_key'];
            if (isset($byRace[$key])) {
                $old = $store->get($byRace[$key])['data'];
                BundleRules::require($old['result_version'] !== $row['result_version'] || $byRace[$key] === $ref, 'CONFLICT: multiple seals for a result version.');
                if ($old['result_version'] > $row['result_version']) {
                    continue;
                }
            }
            $byRace[$key] = $ref;
        }
        ksort($byRace, SORT_STRING);

        return array_values($byRace);
    }
}
