<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Scraping\Services\RaceDistanceObservationService;
use App\Domain\Keirin\Statistics\DTO\Stat38InputDto;
use App\Models\Race;
use App\Models\RaceDistanceObservation;
use App\Models\RaceEntry;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Stat38InputRepository
{
    public function __construct(private readonly RaceDistanceObservationService $observations, private readonly RawReader $raw,
        private readonly RaceLiveResultParser $resultParser) {}

    /** @return \Generator<Stat38InputDto> */
    public function inputs(int $raceId, DateTimeImmutable $asOf): \Generator
    {
        $race = Race::query()->whereKey($raceId)->whereBetween('race_date', ['2022-01-01', '2025-12-31'])->firstOrFail();
        $entries = RaceEntry::query()->where('race_id', $raceId)->orderBy('id')->limit(10)->get();
        if ($entries->isEmpty() || $entries->count() > 9 || $entries->unique('bike_number')->count() !== $entries->count()
            || $entries->unique('external_player_id')->count() !== $entries->count()) {
            throw new RuntimeException('Invalid STAT-38 target entries.');
        }
        foreach ($entries as $entry) {
            yield new Stat38InputDto($raceId, $race->race_date->format('Y-m-d'), $asOf, $this->identity($entry),
                $this->observation($raceId, $asOf), $this->observation($raceId, null), $this->history($race, $entry, $asOf));
        }
    }

    private function history(Race $target, RaceEntry $entry, DateTimeImmutable $asOf): \Generator
    {
        $query = RaceEntry::query()->select('race_entries.*')->join('races', 'races.id', '=', 'race_entries.race_id')
            ->where('race_entries.external_player_id', $entry->external_player_id)->where('race_entries.player_id', $entry->player_id)
            ->whereBetween('races.race_date', ['2022-01-01', '2025-12-31'])
            ->where('races.race_date', '<', $target->race_date->format('Y-m-d'));
        // race_id is unique per player in a valid history; stream in that order to detect duplicates without an unbounded set.
        $lastRace = $lastEntry = 0;
        do {
            $chunk = (clone $query)->where(function ($cursor) use ($lastRace, $lastEntry): void {
                $cursor->where('race_entries.race_id', '>', $lastRace)->orWhere(function ($same) use ($lastRace, $lastEntry): void {
                    $same->where('race_entries.race_id', $lastRace)->where('race_entries.id', '>', $lastEntry);
                });
            })->orderBy('race_entries.race_id')->orderBy('race_entries.id')->limit(100)->get();
            foreach ($chunk as $pastEntry) {
                $past = Race::query()->whereKey($pastEntry->race_id)->whereBetween('race_date', ['2022-01-01', '2025-12-31'])->firstOrFail();
                $results = RaceResult::query()->where('race_id', $past->id)->where('race_entry_id', $pastEntry->id)->limit(2)->get();
                if ($results->count() > 1) {
                    throw new RuntimeException('Duplicate STAT-38 historical result identity.');
                }
                $result = $results->first();
                $import = $result === null ? null : RaceResultImport::query()->whereKey($result->race_result_import_id)->where('race_id', $past->id)->first();
                $link = $result !== null && $result->player_id === $pastEntry->player_id
                    && (int) $result->bike_number === $pastEntry->bike_number && $import !== null
                    ? $this->resultLink($past, $pastEntry, $result, $import) : null;
                yield ['race_id' => (int) $past->id, 'race_date' => $past->race_date->format('Y-m-d'),
                    'scheduled_start_at' => $past->scheduled_start_at?->format(DATE_ATOM),
                    'entry_id' => (int) $pastEntry->id, 'player_id' => $pastEntry->player_id,
                    'external_player_id' => $pastEntry->external_player_id, 'bike_number' => $pastEntry->bike_number,
                    'result_status' => $result?->result_status, 'result_link_verified' => $link !== null,
                    'result_fetched_at' => $result?->fetched_at?->format(DATE_ATOM),
                    'result_saved_at' => $link['saved_at'] ?? null,
                    'result_import_id' => $import?->id, 'result_source_sha256' => $import?->source_hash,
                    'observation' => $this->observation((int) $past->id, null),
                    'prediction_observation' => $this->observation((int) $past->id, $asOf)];
                $lastRace = (int) $pastEntry->race_id;
                $lastEntry = (int) $pastEntry->id;
            }
        } while ($chunk->count() === 100);
    }

    private function observation(int $raceId, ?DateTimeImmutable $asOf): ?array
    {
        $query = RaceDistanceObservation::query()->where('race_id', $raceId)->where('parser_version', RaceDistanceObservationService::VERSION);
        if ($asOf !== null) {
            $query->where('fetched_at', '<', $asOf);
        }
        $versions = $query->orderByRaw('fetched_at DESC NULLS LAST')->orderByDesc('id')->limit(2)->get();
        $observation = $versions->first();
        if ($observation === null) {
            return null;
        }
        $this->observations->verifyStored($observation);
        if ($versions->count() === 2 && $versions[1]->fetched_at == $observation->fetched_at
            && ($versions[1]->planned_distance_m !== $observation->planned_distance_m || $versions[1]->planned_laps !== $observation->planned_laps)) {
            throw new RuntimeException('Conflicting distance observations at the same acquisition time.');
        }

        return ['observation_id' => (int) $observation->id, 'race_id' => (int) $observation->race_id,
            'race_date' => $observation->race_date->format('Y-m-d'), 'planned_distance_m' => $observation->planned_distance_m,
            'planned_laps' => $observation->planned_laps, 'distance_status' => $observation->distance_status,
            'laps_status' => $observation->laps_status, 'fetched_at' => $observation->fetched_at?->format(DATE_ATOM),
            'saved_at' => $observation->metadata['fetch_log_created_at'] ?? null,
            'scheduled_start_at' => $observation->scheduled_start_at?->format(DATE_ATOM),
            'fetch_log_id' => (int) $observation->scraping_fetch_log_id, 'raw_sha256' => $observation->raw_sha256,
            'source_metadata_hash' => $observation->source_metadata_hash, 'entrants' => $observation->entrants];
    }

    private function identity(RaceEntry $entry): array
    {
        return ['id' => (int) $entry->id, 'race_id' => (int) $entry->race_id, 'player_id' => $entry->player_id,
            'bike_number' => $entry->bike_number, 'external_player_id' => $entry->external_player_id];
    }

    private function resultLink(Race $race, RaceEntry $entry, RaceResult $result, RaceResultImport $import): ?array
    {
        $log = ScrapingFetchLog::query()->find($import->scraping_fetch_log_id);
        if ($log === null || $log->source !== $race->source || (int) $log->http_status !== 200
            || $log->request_method !== 'POST' || $log->request_url !== rtrim((string) config('keirin.base_url'), '/').config('keirin.routes.race_live')
            || $import->import_status !== 'SUCCEEDED' || $import->error_type !== null
            || $log->fetched_at != $result->fetched_at
            || $log->error_type !== null || $log->sha256 !== $import->source_hash
            || $log->raw_file_path !== $import->raw_file_path || (int) $log->response_size !== (int) $import->raw_response_size
            || ! $import->utf8_conversion_succeeded || ($log->request_parameters['disp'] ?? null) !== 'PJ0326') {
            return null;
        }
        $root = realpath(Storage::disk(config('keirin.raw_disk'))->path(''));
        $relative = $import->raw_file_path;
        if ($root === false || ! is_string($relative) || $relative === '' || str_starts_with($relative, '/')
            || str_contains($relative, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//|\x00~', $relative) === 1) {
            return null;
        }
        $page = $this->resultParser->parse($this->raw->read(['race_date' => $race->race_date->format('Y-m-d'), 'absolute_path' => $root.'/'.$relative,
            'source_hash' => $import->source_hash, 'raw_response_size' => (int) $import->raw_response_size,
            'fetch_hash' => $log->sha256, 'fetch_bytes' => (int) $log->response_size,
            'content_type' => $log->content_type, 'converted_hash' => $import->converted_hash]));
        $track = Racetrack::query()->findOrFail($race->racetrack_id);
        if ($page->raceDate !== $race->race_date->format('Ymd') || $page->trackCode !== $track->external_track_id
            || $page->raceNumber !== (int) $race->race_number || ! $page->resultPage->resultParsingComplete) {
            throw new RuntimeException('STAT-38 historical participation Raw target mismatch.');
        }
        foreach ($page->resultPage->results as $row) {
            if ($row->bikeNumber === $entry->bike_number && $row->externalPlayerId === $entry->external_player_id
                && $row->status->value === $result->result_status) {
                $savedAt = $log->created_at !== null && $import->imported_at !== null ? max($log->created_at, $import->imported_at) : null;

                return ['saved_at' => $savedAt?->format(DATE_ATOM)];
            }
        }

        throw new RuntimeException('STAT-38 historical participation Raw entrant/status mismatch.');
    }
}
