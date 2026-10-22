<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\OfficialMethodHistory;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Reader
{
    public function __construct(private readonly RawReader $raw, private readonly RaceLiveResultParser $parser,
        private readonly EmbeddedJsonExtractor $json) {}

    public function read(Race $race, RaceEntry $entry): array
    {
        $date = $race->race_date->format('Y-m-d');
        if ($date < '2022-01-01' || $date > '2025-12-31' || (int) $entry->race_id !== (int) $race->id) {
            throw new RuntimeException('Invalid/forbidden official history target.');
        }
        $identity = RaceEntry::query()->where('race_id', $race->id)->where(function ($q) use ($entry): void {
            $q->where('bike_number', $entry->bike_number)->orWhere('external_player_id', $entry->external_player_id);
        })->limit(2)->get();
        if ($identity->count() !== 1 || (int) $identity[0]->id !== (int) $entry->id) {
            throw new RuntimeException('Ambiguous official result entrant identity.');
        }
        $unverified = ['evidence_verified' => false, 'rank' => null, 'result_status' => null, 'method_raw' => null,
            'method' => null, 'method_missing' => false, 'participation' => 'UNKNOWN', 'issues' => ['RESULT_EVIDENCE_MISSING']];
        if ($race->result_status === 'CANCELLED') {
            return array_replace($unverified, ['participation' => 'CANCELLED', 'issues' => ['CANCELLED_DB_STATE']]);
        }
        $results = RaceResult::query()->where('race_id', $race->id)->where('bike_number', $entry->bike_number)->limit(2)->get();
        if ($results->count() > 1) {
            throw new RuntimeException('Duplicate official result rows.');
        }
        $result = $results->first();
        if ($result === null || ! in_array($race->result_status, ['CONFIRMED', 'CORRECTED'], true)) {
            return $unverified;
        }
        if (($result->race_entry_id !== null && (int) $result->race_entry_id !== (int) $entry->id)
            || ($result->player_id !== null && ($entry->player_id === null || (int) $result->player_id !== (int) $entry->player_id))) {
            throw new RuntimeException('Official result stored ID mismatch.');
        }
        $import = RaceResultImport::query()->find($result->race_result_import_id);
        $log = $import === null ? null : ScrapingFetchLog::query()->find($import->scraping_fetch_log_id);
        if ($import === null || $log === null) {
            return $unverified;
        }
        if ((int) $import->race_id !== (int) $race->id || $import->import_status !== 'SUCCEEDED' || $import->error_type !== null
            || $race->source !== config('keirin.source') || $log->source !== $race->source || $log->request_method !== 'POST' || (int) $log->http_status !== 200
            || $log->request_url !== rtrim((string) config('keirin.base_url'), '/').config('keirin.routes.race_live')
            || $log->error_type !== null || ($log->request_parameters['disp'] ?? null) !== 'PJ0326'
            || $import->source_url !== $log->request_url || $result->source_url !== $import->source_url
            || ! $import->utf8_conversion_succeeded || ! $log->utf8_conversion_succeeded
            || ! is_string($import->parser_version) || $import->parser_version === ''
            || ! is_string($log->parser_version) || $log->parser_version === ''
            || $log->raw_file_path !== $import->raw_file_path || $log->sha256 !== $import->source_hash
            || (int) $log->response_size !== (int) $import->raw_response_size) {
            throw new RuntimeException('Official result import/fetch source mismatch.');
        }
        $root = realpath(Storage::disk(config('keirin.raw_disk'))->path(''));
        $relative = $import->raw_file_path;
        if ($root === false || ! is_string($relative) || $relative === '' || str_starts_with($relative, '/')
            || str_contains($relative, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//|\x00~', $relative) === 1) {
            throw new RuntimeException('Invalid official result Raw path.');
        }
        $source = ['race_date' => $date, 'absolute_path' => $root.'/'.$relative, 'source_hash' => $import->source_hash,
            'raw_response_size' => (int) $import->raw_response_size, 'fetch_hash' => $log->sha256,
            'fetch_bytes' => (int) $log->response_size, 'converted_hash' => $import->converted_hash, 'content_type' => $log->content_type];
        $html = $this->raw->read($source);
        $page = $this->parser->parse($html);
        $track = Racetrack::query()->findOrFail($race->racetrack_id);
        if ($page->raceDate !== $race->race_date->format('Ymd') || $page->trackCode !== $track->external_track_id
            || $page->raceNumber !== (int) $race->race_number || ! $page->resultPage->resultParsingComplete) {
            throw new RuntimeException('Official result Raw race identity mismatch.');
        }
        $bikes = $players = [];
        foreach ($page->resultPage->results as $parsed) {
            if (isset($bikes[$parsed->bikeNumber]) || isset($players[$parsed->externalPlayerId])) {
                throw new RuntimeException('Duplicate official Raw result identity.');
            }
            $bikes[$parsed->bikeNumber] = true;
            $players[$parsed->externalPlayerId] = true;
        }
        if (count($bikes) > 9 || count($bikes) !== (int) $import->result_count) {
            throw new RuntimeException('Official Raw result count mismatch.');
        }
        $matches = array_values(array_filter($page->resultPage->results, fn ($r) => $r->bikeNumber === $entry->bike_number
            || $r->externalPlayerId === $entry->external_player_id));
        if (count($matches) !== 1 || $matches[0]->bikeNumber !== $entry->bike_number || $matches[0]->externalPlayerId !== $entry->external_player_id
            || $matches[0]->rank !== $result->rank || $matches[0]->status->value !== $result->result_status
            || $matches[0]->winningTechnique !== HtmlTextNormalizer::normalize($result->winning_technique)) {
            throw new RuntimeException('Official result Raw entrant/rank/status/method mismatch.');
        }
        $rawRows = $this->json->extract($html, 'PJ0326')['tyakujyunItemSubData'];
        $rawRow = array_values(array_filter($rawRows, fn ($r) => (int) $r['syaban'] === $entry->bike_number))[0];
        $outcome = Contract::outcome($result->rank, $result->result_status, $matches[0]->winningTechnique);
        $saved = [$log->created_at, $log->updated_at, $import->imported_at, $import->created_at, $import->updated_at,
            $result->fetched_at, $result->updated_at];
        foreach ($saved as $stamp) {
            if ($stamp !== null && $log->fetched_at !== null && $stamp < $log->fetched_at) {
                throw new RuntimeException('Official result acquisition/save chronology mismatch.');
            }
        }
        if (($import->updated_at !== null && $import->imported_at !== null && $import->updated_at < $import->imported_at)
            || ($result->updated_at !== null && $result->fetched_at !== null && $result->updated_at < $result->fetched_at)) {
            throw new RuntimeException('Official result processing/save chronology mismatch.');
        }
        $this->raw->verify($source);

        return $outcome + ['evidence_verified' => true, 'rank' => $result->rank, 'result_status' => $result->result_status,
            'method_raw' => $rawRow['kimarite'] ?? null, 'method_present' => array_key_exists('kimarite', $rawRow),
            'issues' => $outcome['method_missing'] ? ['MISSING_TOP2_METHOD'] : [],
            'result_id' => (int) $result->id, 'import_id' => (int) $import->id, 'fetch_log_id' => (int) $log->id,
            'raw_sha256' => $log->sha256, 'raw_bytes' => (int) $log->response_size, 'raw_file_path' => $relative,
            'source_parser_version' => $log->parser_version, 'import_parser_version' => $import->parser_version,
            'fetched_at' => $log->fetched_at?->format(DATE_ATOM), 'fetch_log_saved_at' => $log->created_at?->format(DATE_ATOM),
            'result_processed_at' => $result->fetched_at?->format(DATE_ATOM),
            'saved_at' => in_array(null, $saved, true) ? null : max($saved)->format(DATE_ATOM),
            'identity_evidence' => ['method' => 'OFFICIAL_RAW_VERIFIED', 'stored_entry_id' => $result->race_entry_id,
                'stored_player_id' => $result->player_id, 'matched_entry_id' => (int) $entry->id, 'matched_player_id' => $entry->player_id],
            'correction_effective_at' => null, 'publication_time_status' => 'UNKNOWN', 'data_role' => 'DEVELOPMENT_RETROSPECTIVE'];
    }
}
