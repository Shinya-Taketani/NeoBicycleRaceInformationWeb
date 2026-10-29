<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\PrestartEvidence\RawObservationDecoder;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use stdClass;
use Throwable;

final class ObservationParser
{
    public function parse(string $bytes, ?string $contentType, array $race): array
    {
        try {
            [$html, $encoding] = (new CharacterEncodingConverter)->convertToUtf8($bytes, $contentType);
            $decoder = new RawObservationDecoder;
            $pc = $decoder->embedded($html, 'PC0201');
            $detail = $pc['C0201data']->C0201racedtl ?? null;
            $category = (new RaceCategoryPolicy)->classify(is_string($detail->syumoku ?? null) ? $detail->syumoku : null);
            if ($category !== RaceCategory::Men) {
                return ['status' => 'SKIPPED_UNSUPPORTED_CATEGORY', 'category' => $category->value];
            }
            $page = (new RaceDetailParser(new EmbeddedJsonExtractor))->parse($html);
            if ($page->raceDate !== str_replace('-', '', $race['race_date']) || $page->trackCode !== $race['track_code']
                || $page->raceNumber !== $race['race_number']) {
                return ['status' => 'RACE_IDENTITY_MISMATCH'];
            }
            $pj = $decoder->embedded($html, 'PJ0315');
            $entries = $attributes = [];
            foreach ($page->entries as $index => $entry) {
                $entries[] = ['bike_number' => $entry->bikeNumber, 'external_player_id' => $entry->externalPlayerId,
                    'frame_number' => $entry->frameNumber];
                $attributes[(string) $entry->bikeNumber] = self::project($pj['sensyuTypeInfo'][$index], [
                    'syaban', 'wakuban', 'sensyuRegistNo', 'sensyuName', 'huKen', 'prevKyuhan', 'kyuhan', 'kyakusitu',
                    'sotugyouki', 'age', 'heikinTokuten', 'nigeCnt', 'makuriCnt', 'sasiCnt', 'markCnt', 'backCnt',
                    'homeTori', 'stTori', 'syouritu', 'rentairitu2', 'rentairitu3']);
            }
            RaceRosterSnapshotDto::validateEntries($entries);

            return ['status' => 'PARSED_RACE_IDENTITY_VERIFIED', 'category' => 'MEN', 'encoding' => $encoding,
                'race' => $race, 'entries' => $entries, 'scheduled_start' => $page->startTime,
                'race_attributes' => [...self::project($pc['C0201data'], ['selKaisai', 'selKjyoCd', 'selRaceNo']),
                    ...self::project($detail, ['syumoku', 'kyori', 'syukai', 'nameKyosou', 'aftStartTime', 'aftBetTime'])],
                'entry_attributes' => $attributes, 'official_change_reason' => 'UNKNOWN'];
        } catch (Throwable $error) {
            return ['status' => 'PARSER_OR_INTERNAL_IDENTITY_FAILED', 'exception_class' => $error::class];
        }
    }

    private static function project(stdClass $object, array $fields): array
    {
        $projection = [];
        foreach ($fields as $field) {
            $projection[$field] = property_exists($object, $field)
                ? ['present' => true, ...self::typed($object->{$field})] : ['present' => false];
        }

        return $projection;
    }

    private static function typed(mixed $value): array
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);
            $value = array_map(self::typed(...), $properties);

            return ['type' => 'object', 'value' => $value];
        }
        if (is_array($value)) {
            return ['type' => 'array', 'value' => array_map(self::typed(...), $value)];
        }

        return ['type' => get_debug_type($value), 'value' => $value];
    }
}
