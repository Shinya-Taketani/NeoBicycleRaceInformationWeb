<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\Reader;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceResultImport;
use App\Models\ScrapingFetchLog;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SavedResultWeatherProjectionService
{
    public const VERSION = 'STAT-21-SAVED-PJ0326-RAW-FIELDS-v1';

    public function __construct(private readonly Reader $identity, private readonly RawReader $raw,
        private readonly RaceLiveResultParser $parser, private readonly EmbeddedJsonExtractor $json) {}

    public function project(Race $race, RaceEntry $entry): array
    {
        $binding = $this->identity->read($race, $entry);
        if (! ($binding['evidence_verified'] ?? false) || ! isset($binding['import_id'], $binding['fetch_log_id'])) {
            throw new RuntimeException('Weather projection requires verified official result binding.');
        }
        $import = RaceResultImport::query()->findOrFail($binding['import_id']);
        $log = ScrapingFetchLog::query()->findOrFail($binding['fetch_log_id']);
        $root = realpath(Storage::disk(config('keirin.raw_disk'))->path(''));
        if ($root === false) {
            throw new RuntimeException('Verified weather Raw root unavailable.');
        }
        $source = ['race_date' => $race->race_date->format('Y-m-d'), 'absolute_path' => $root.'/'.$import->raw_file_path,
            'source_hash' => $import->source_hash, 'raw_response_size' => (int) $import->raw_response_size,
            'fetch_hash' => $log->sha256, 'fetch_bytes' => (int) $log->response_size,
            'converted_hash' => $import->converted_hash, 'content_type' => $log->content_type];
        $html = $this->raw->read($source);
        $page = $this->parser->parse($html);
        $fields = $this->fields($this->json->extract($html, 'PJ0326'));
        $this->raw->verify($source);

        return ['version' => self::VERSION, 'race_id' => (int) $race->id, 'race_date' => $source['race_date'],
            'track_code' => $page->trackCode, 'race_number' => $page->raceNumber,
            'identity_checked_entry_id' => (int) $entry->id, 'identity_verified' => true,
            'raw_fields' => $fields, 'parser_weather' => $page->weather, 'parser_wind_speed' => $page->windSpeed,
            'page_last_updated_at_raw' => $page->lastUpdatedAt, 'meteorological_observed_at' => null,
            'meteorological_time_status' => 'NOT_PROVEN_BY_FETCH_OR_PAGE_UPDATE_TIME',
            'evidence' => array_intersect_key($binding, array_flip(['import_id', 'fetch_log_id', 'raw_sha256', 'raw_bytes', 'raw_file_path',
                'source_parser_version', 'import_parser_version', 'fetched_at', 'fetch_log_saved_at', 'result_processed_at', 'saved_at',
                'raw_detected_status', 'requested_result_status', 'parsed_page_status', 'stored_race_status'])),
            'data_role' => 'RETROSPECTIVE_ONLY', 'historical_as_of_available' => false,
            'prediction_use' => 'NOT_AUTHORIZED', 'points' => null, 'confidence' => null];
    }

    public function fields(array $raw): array
    {
        $out = [];
        foreach (['tenki', 'husoku'] as $key) {
            $present = array_key_exists($key, $raw);
            $value = $raw[$key] ?? null;
            $number = null;
            $state = match (true) {
                ! $present => 'MISSING', $value === null => 'NULL', $value === '' => 'EMPTY',
                ! is_string($value) && ! is_int($value) && ! is_float($value) => 'INVALID_TYPE',
                default => 'PRESENT',
            };
            if ($key === 'husoku' && $state === 'PRESENT') {
                if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', (string) $value) === 1 && is_finite((float) $value)) {
                    $number = (float) $value;
                    $state = $number === 0.0 ? 'NUMERIC_ZERO_UNIT_UNVERIFIED' : 'NUMERIC_UNIT_UNVERIFIED';
                } else {
                    $state = 'NON_NUMERIC_OR_INVALID';
                }
            }
            $out[$key] = ['present' => $present, 'raw' => $value, 'raw_type' => $present ? get_debug_type($value) : 'MISSING',
                'state' => $state, 'numeric_token' => $number, 'unit' => null, 'unit_status' => 'NOT_VERIFIED'];
        }

        return $out;
    }
}
