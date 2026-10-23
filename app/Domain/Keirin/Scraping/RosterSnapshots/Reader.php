<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\RosterSnapshots;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\PrestartEvidence\RawObservationDecoder;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Reader
{
    public const CATEGORY_POLICY_VERSION = 'STAT46-ROSTER-CATEGORY-ACCEPTANCE-v1-PR120';

    public function __construct(
        private readonly RaceDetailParser $parser,
        private readonly RawObservationDecoder $json,
        private readonly RaceCategoryPolicy $categories,
    ) {}

    /** The expected race identity is fixed by the caller, never the current roster. */
    public function project(string $html, array $expected, array $capture, ?string $databaseRaceType = null): RaceRosterSnapshotDto
    {
        $page = $this->parser->parse($html);
        if ($page->raceDate !== str_replace('-', '', $expected['race_date']) || $page->trackCode !== $expected['track_code']
            || $page->raceNumber !== $expected['race_number']) {
            throw new RuntimeException('PJ0315 roster race/date/track/number mismatch.');
        }
        $rawCategory = $this->categories->classify($page->raceType);
        $databaseCategory = $this->categories->classify($databaseRaceType);
        if ($rawCategory !== RaceCategory::Unknown && $databaseCategory !== RaceCategory::Unknown && $rawCategory !== $databaseCategory) {
            throw new RuntimeException('ROSTER_CATEGORY_MISMATCH: raw='.$rawCategory->value.', database='.$databaseCategory->value);
        }
        // Only the historical Raw can establish this capture as Men; current DB is never a fallback.
        if ($rawCategory !== RaceCategory::Men) {
            throw new UnsupportedCategory($rawCategory, $databaseCategory);
        }
        $pc = $this->json->embedded($html, 'PC0201')['C0201data'];
        $detail = $pc->C0201racedtl;
        $rawEntries = $this->json->embedded($html, 'PJ0315')['sensyuTypeInfo'];
        if (! is_array($rawEntries) || ! array_is_list($rawEntries) || count($rawEntries) !== count($page->entries)) {
            throw new RuntimeException('Incomplete PJ0315 roster.');
        }
        $summaryRows = $detail->C0201sensyu;
        if (! is_array($summaryRows) || ! array_is_list($summaryRows)) {
            throw new RuntimeException('PC0201 roster list required.');
        }
        $entries = [];
        $summary = [];
        foreach ($summaryRows as $item) {
            if (! is_string($item->numPlayer) || preg_match('/^[0-9]{6}$/D', $item->numPlayer) !== 1
                || isset($summary[(int) $item->carNum]) || in_array($item->numPlayer, $summary, true)) {
                throw new RuntimeException('Invalid/duplicate PC0201 roster identity.');
            }
            $summary[(int) $item->carNum] = $item->numPlayer;
        }
        foreach ($page->entries as $index => $entry) {
            $raw = (array) $rawEntries[$index];
            if (! is_string($raw['sensyuRegistNo'] ?? null) || $raw['sensyuRegistNo'] !== $entry->externalPlayerId
                || ($summary[$entry->bikeNumber] ?? null) !== $entry->externalPlayerId) {
                throw new RuntimeException('PC0201/PJ0315 roster identity mismatch.');
            }
            $fields = [];
            foreach (['syaban', 'wakuban', 'sensyuRegistNo', 'sensyuName', 'kyuhan', 'kyakusitu', 'heikinTokuten'] as $key) {
                $fields[$key] = $this->field($raw, $key, 'PJ0315:/sensyuTypeInfo/'.$index.'/'.$key);
            }
            $entries[] = ['bike_number' => $entry->bikeNumber, 'frame_number' => $entry->frameNumber,
                'external_player_id' => $entry->externalPlayerId, 'player_name' => $entry->playerName,
                'grade' => $entry->grade, 'riding_style' => $entry->ridingStyle, 'race_score' => $entry->raceScore,
                'raw_fields' => $fields];
        }
        RaceRosterSnapshotDto::validateEntries($entries);
        if (count($summary) !== count($entries)) {
            throw new RuntimeException('PC0201/PJ0315 roster set mismatch.');
        }
        $start = $page->startTime === null ? null : DateTimeImmutable::createFromFormat('!Ymd H:i', $page->raceDate.' '.$page->startTime, new DateTimeZone('Asia/Tokyo'));
        if ($start === false) {
            throw new RuntimeException('Invalid roster same-Raw scheduled start.');
        }
        $fetched = $capture['fetched_at'];

        return new RaceRosterSnapshotDto([...$expected, ...$capture, 'source' => 'PJ0315',
            'entrant_count' => count($entries), 'entries' => $entries,
            'scheduled_start_at' => $start?->format(DATE_ATOM),
            'schedule_raw' => $this->field((array) $detail, 'aftStartTime', 'PC0201:/C0201data/C0201racedtl/aftStartTime'),
            'schedule_timezone' => 'Asia/Tokyo', 'schedule_timezone_basis' => 'EXISTING_PJ0315_PARSER_CONVENTION',
            'timing_status' => $fetched === null || $start === null ? 'UNKNOWN'
                : ((new DateTimeImmutable($fetched)) < $start ? 'BEFORE_SAME_RAW_START' : 'AT_OR_AFTER_SAME_RAW_START'),
            'parser_version' => RaceRosterSnapshotDto::VERSION, 'snapshot_version' => RaceRosterSnapshotDto::VERSION,
            'official_publication_status' => 'UNKNOWN', 'official_publication_at' => null,
            'use_class' => 'RETROSPECTIVE_ONLY', 'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED']);
    }

    private function field(array $row, string $key, string $pointer): array
    {
        $present = array_key_exists($key, $row);
        $value = $row[$key] ?? null;

        return ['present' => $present, 'raw_type' => $present ? get_debug_type($value) : 'MISSING',
            'raw_json' => $present ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE) : null,
            'source_pointer' => $pointer];
    }
}
