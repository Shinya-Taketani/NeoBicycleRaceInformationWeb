<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Exceptions\CharacterEncodingConversionException;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\PrestartEvidence\RawObservationDecoder;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use Throwable;

final class Inspector
{
    public function inspect(string $bytes, ?string $contentType, Target $target): array
    {
        $result = ['status' => 'NOT_PARSED', 'encoding' => null, 'structure' => 'UNKNOWN',
            'race_identity' => 'NOT_ESTABLISHED', 'entrant_identity' => 'NOT_ESTABLISHED', 'attributes' => null];
        if ($bytes === '') {
            return [...$result, 'status' => 'EMPTY_RESPONSE'];
        }
        try {
            [$html, $encoding] = (new CharacterEncodingConverter)->convertToUtf8($bytes, $contentType);
            $result['encoding'] = $encoding;
            $overview = (new RawObservationDecoder)->embedded($html, 'PC0201');
            $type = $overview['C0201data']->C0201racedtl->syumoku ?? null;
            $category = (new RaceCategoryPolicy)->classify(is_string($type) ? $type : null);
            if ($category !== RaceCategory::Men) {
                return [...$result, 'status' => 'SKIPPED_UNSUPPORTED_CATEGORY'];
            }
            $page = (new RaceDetailParser(new EmbeddedJsonExtractor))->parse($html);
            $result['structure'] = 'HTML_EMBEDDED_PC0201_AND_PJ0315';
            if ($page->raceDate !== str_replace('-', '', $target->race['race_date']) || $page->trackCode !== $target->race['track_code']
                || $page->raceNumber !== $target->race['race_number']) {
                return [...$result, 'status' => 'RACE_IDENTITY_MISMATCH'];
            }
            $result['race_identity'] = 'VERIFIED';
            $actual = $expected = [];
            foreach ($page->entries as $entry) {
                if (isset($actual[$entry->bikeNumber])) {
                    return [...$result, 'status' => 'ENTRANT_IDENTITY_MISMATCH'];
                }
                $actual[$entry->bikeNumber] = $entry->externalPlayerId;
            }
            foreach ($target->entries as $entry) {
                $expected[$entry['bike_number']] = $entry['external_player_id'];
            }
            ksort($actual);
            ksort($expected);
            if ($actual !== $expected) {
                return [...$result, 'status' => 'ENTRANT_IDENTITY_MISMATCH'];
            }
            $attributes = get_object_vars($page);
            $attributes['entries'] = array_map(fn ($entry) => get_object_vars($entry), $page->entries);
            $missing = [];
            foreach ($attributes['entries'] as $entry) {
                foreach ($entry as $field => $value) {
                    if ($value === null) {
                        $missing[$field] = ($missing[$field] ?? 0) + 1;
                    }
                }
            }

            return [...$result, 'status' => 'PARSED_IDENTITY_VERIFIED', 'entrant_identity' => 'VERIFIED',
                'entrant_count' => count($actual), 'attributes' => $attributes, 'missing_entry_attributes' => $missing,
                'missing_race_attributes' => array_keys(array_filter(get_object_vars($page), fn ($value) => $value === null)),
                'official_publication_at' => null, 'historical_as_of_available' => false];
        } catch (CharacterEncodingConversionException) {
            return [...$result, 'status' => 'ENCODING_FAILED'];
        } catch (Throwable $error) {
            return [...$result, 'status' => 'PARSER_FAILED', 'exception_class' => $error::class];
        }
    }
}
