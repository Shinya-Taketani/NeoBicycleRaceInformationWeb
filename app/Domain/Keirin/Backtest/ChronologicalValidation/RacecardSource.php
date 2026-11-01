<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use RuntimeException;

final class RacecardSource
{
    public function __construct(private readonly RaceDetailParser $parser, private readonly CharacterEncodingConverter $encoding, private readonly RaceCategoryPolicy $categories) {}

    public function project(array $candidate, Scope $scope): array
    {
        $scope->target($candidate['race_date']);
        BundleRules::require($candidate['original_race_id'] === null || (is_int($candidate['original_race_id']) && $candidate['original_race_id'] > 0), 'Invalid original race ID.');
        $date = Scope::day($candidate['race_date']);
        $key = $candidate['track_code'].':'.$date->format('Ymd').':'.sprintf('%02d', $candidate['race_number']);
        BundleRules::require($candidate['external_race_key'] === $key, 'Candidate external race identity mismatch.');
        $result = ['candidate' => $candidate, 'race' => null, 'entries' => [], 'context' => $candidate['context'],
            'input_as_of' => null, 'cutoff_source' => null, 'capture_refs' => [], 'reasons' => [], 'exclusion' => null];
        if ($candidate['capture'] === null) {
            $result['reasons'] = ['KNOWN_INPUT_SCHEMA_UNAVAILABLE'];

            return $result;
        }
        $capture = $candidate['capture'];
        BundleRules::require($capture['source'] === 'PJ0315' && $capture['method'] === 'POST'
            && $capture['source_status'] === 'VERIFIED' && $capture['material_kind'] === $scope->kind, 'Offline capture source/scope mismatch.');
        Scope::instant($capture['fetched_at']);
        Scope::instant($capture['saved_at']);
        BundleRules::require(Scope::instant($capture['fetched_at']) <= Scope::instant($capture['saved_at']), 'Capture save precedes fetch.');
        Json::verify($capture['private_ref'], $capture['raw']);
        [$html] = $this->encoding->convertToUtf8(file_get_contents($capture['private_ref']), $capture['content_type']);
        $page = $this->parser->parse($html);
        Json::verify($capture['private_ref'], $capture['raw']);
        BundleRules::require($page->raceDate === $date->format('Ymd') && $page->trackCode === $candidate['track_code']
            && $page->raceNumber === $candidate['race_number'], 'PJ0315 race identity mismatch.');
        $result['capture_refs'] = [array_intersect_key($capture, array_flip(['capture_id', 'source', 'raw', 'private_ref', 'method', 'source_status', 'parser_version', 'fetched_at', 'saved_at']))];
        $category = $this->categories->classify($page->raceType);
        $count = count($page->entries);
        if ($category === RaceCategory::Girls || $count < 5 || $count > 9) {
            $result['exclusion'] = $category === RaceCategory::Girls ? 'SKIPPED_UNSUPPORTED_CATEGORY' : 'UNSUPPORTED_ENTRANT_COUNT';

            return $result;
        }
        if ($category === RaceCategory::Unknown) {
            $result['reasons'][] = 'CATEGORY_UNDETERMINED';
        }
        $start = $page->startTime === null ? null : $candidate['race_date'].'T'.$page->startTime.':00+09:00';
        $close = $page->salesCloseTime === null ? null : $candidate['race_date'].'T'.$page->salesCloseTime.':00+09:00';
        if ($start === null) {
            $result['reasons'][] = 'MISSING_SCHEDULE';
        }
        if ($start !== null && $close !== null && Scope::instant($close) > Scope::instant($start)) {
            throw new RuntimeException('Sales close follows scheduled start.');
        }
        $result['input_as_of'] = $close ?? $start;
        $result['cutoff_source'] = $close !== null ? 'SALES_CLOSE_AT' : 'SCHEDULED_START_AT_FALLBACK';
        foreach (['meeting_id', 'meeting_start', 'meeting_end', 'original_race_id'] as $field) {
            if ($candidate[$field] === null) {
                $result['reasons'][] = $field === 'original_race_id' ? 'UNRESOLVED_IDENTITY' : 'MISSING_MEETING_CONTEXT';
            }
        }
        if ($candidate['meeting_start'] !== null && $candidate['meeting_end'] !== null) {
            Scope::day($candidate['meeting_start']);
            Scope::day($candidate['meeting_end']);
            BundleRules::require($candidate['meeting_start'] <= $candidate['race_date'] && $candidate['race_date'] <= $candidate['meeting_end'], 'Meeting period mismatch.');
        }
        BundleRules::require($count === $candidate['entrant_count'] && $count === count($candidate['entries']), 'Fixed roster size mismatch.');
        $byBike = [];
        foreach ($page->entries as $entry) {
            BundleRules::require(! isset($byBike[$entry->bikeNumber]), 'Duplicate Raw bike.');
            $byBike[$entry->bikeNumber] = $entry;
        }
        $ids = $players = $bikes = $external = [];
        foreach ($candidate['entries'] as $identity) {
            $entry = $byBike[$identity['bike']] ?? null;
            BundleRules::require($entry !== null && $entry->externalPlayerId === $identity['external_player_id'], 'Raw and fixed identity differ.');
            foreach (['original_entry_id', 'original_player_id'] as $field) {
                BundleRules::require($identity[$field] === null || (is_int($identity[$field]) && $identity[$field] > 0), 'Invalid original identity.');
                if ($identity[$field] === null) {
                    $result['reasons'][] = 'UNRESOLVED_IDENTITY';
                }
            }
            $ids[] = $identity['original_entry_id'];
            $players[] = $identity['original_player_id'];
            $bikes[] = $identity['bike'];
            $external[] = $identity['external_player_id'];
            $result['entries'][] = [...$identity, 'frame_number' => $entry->frameNumber, 'grade' => $entry->grade, 'race_score_raw' => $entry->raceScore];
        }
        foreach ([$ids, $players, $bikes, $external] as $values) {
            $known = array_values(array_filter($values, fn ($v): bool => $v !== null));
            BundleRules::require(count($known) === count(array_unique($known)), 'Conflicting duplicate identity.');
        }
        $result['race'] = array_intersect_key($candidate, array_flip(['original_race_id', 'external_race_key', 'race_date', 'track_code', 'race_number', 'entrant_count', 'meeting_id', 'meeting_start', 'meeting_end', 'day_number']));
        $result['race'] += ['race_type' => $page->raceType, 'scheduled_start_at' => $start, 'sales_close_at' => $close];
        $result['reasons'] = array_values(array_unique($result['reasons']));

        return $result;
    }
}
