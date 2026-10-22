<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Scraping\DTO\RaceTacticObservationDto;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\ScrapingFetchLog;
use App\Repositories\RaceTacticObservationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RaceTacticObservationService
{
    public const VERSION = 'PJ0315-TACTIC-BHS-OBSERVATION-v1';

    public function __construct(private readonly RawReader $raw, private readonly RaceDistanceObservationService $source,
        private readonly RaceDetailParser $parser, private readonly EmbeddedJsonExtractor $json,
        private readonly RaceTacticObservationRepository $observations) {}

    public function import(int $raceId, int $fetchLogId, bool $dryRun): array
    {
        return DB::transaction(function () use ($raceId, $fetchLogId, $dryRun): array {
            $query = Race::query()->whereKey($raceId)->whereBetween('race_date', ['2022-01-01', '2025-12-31']);
            $race = ($dryRun ? $query : $query->lockForUpdate())->firstOrFail();
            $log = ScrapingFetchLog::query()->findOrFail($fetchLogId);
            $source = $this->rawSource($race, $log);
            $html = $this->raw->read($source);
            // Reuse STAT-38's source/date/track/number validation without writing distance observations.
            $capture = $this->source->prepare($race, $log, $html);
            $page = $this->parser->parse($html);
            $entries = RaceEntry::query()->where('race_id', $raceId)->orderBy('bike_number')->limit(10)->get();
            if ($entries->count() !== count($page->entries) || $entries->count() > 9 || $entries->isEmpty()
                || ($race->entrant_count !== null && (int) $race->entrant_count !== $entries->count())
                || $entries->unique('external_player_id')->count() !== $entries->count()
                || $entries->unique('bike_number')->count() !== $entries->count()) {
                throw new RuntimeException('Tactic observation entrant set mismatch.');
            }
            $rawRows = $this->json->extract($html, 'PJ0315')['sensyuTypeInfo'];
            $rows = [];
            foreach ($page->entries as $offset => $parsed) {
                $entry = $entries->firstWhere('bike_number', $parsed->bikeNumber);
                if ($entry === null || $entry->external_player_id !== $parsed->externalPlayerId) {
                    throw new RuntimeException('Tactic observation bike/registration mismatch.');
                }
                $counts = $fields = [];
                foreach (RaceTacticObservationDto::FIELDS as $rawKey => [$column, $property]) {
                    $present = array_key_exists($rawKey, $rawRows[$offset]);
                    $value = $rawRows[$offset][$rawKey] ?? null;
                    $counts[$column] = $parsed->$property;
                    $fields[$rawKey] = ['present' => $present, 'raw' => $value,
                        'type' => $present ? get_debug_type($value) : 'MISSING',
                        'state' => match (true) {
                            ! $present => 'MISSING', $value === null => 'NULL', $value === '' => 'EMPTY_STRING',
                            $value === '－' => 'PLACEHOLDER', $parsed->$property === 0 => 'OBSERVED_ZERO', default => 'OBSERVED_POSITIVE',
                        }, 'source_pointer' => 'PJ0315.sensyuTypeInfo['.$offset.'].'.$rawKey];
                }
                $dto = new RaceTacticObservationDto($capture, (int) $entry->id, $entry->player_id,
                    $parsed->bikeNumber, $parsed->externalPlayerId, $counts, $fields);
                $rows[] = $this->observations->append($dto, self::VERSION, $dryRun) + ['race_entry_id' => (int) $entry->id];
            }
            $this->raw->verify($source);
            $again = $this->source->prepare($race, $log->fresh(), $html);
            if ($again->sourceMetadataHash !== $capture->sourceMetadataHash) {
                throw new RuntimeException('Tactic fetch metadata changed before publication.');
            }

            return ['race_id' => $raceId, 'fetch_log_id' => $fetchLogId, 'dry_run' => $dryRun,
                'planned_new_observations' => array_sum(array_column($rows, 'planned_new_observations')), 'entries' => $rows,
                'prediction_use' => 'NOT_AUTHORIZED', 'use' => 'RETROSPECTIVE_ONLY'];
        });
    }

    private function rawSource(Race $race, ScrapingFetchLog $log): array
    {
        $root = realpath(Storage::disk(config('keirin.raw_disk'))->path(''));
        $relative = $log->raw_file_path;
        if ($root === false || ! is_string($relative) || $relative === '' || str_starts_with($relative, '/')
            || str_contains($relative, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//|\x00~', $relative) === 1) {
            throw new RuntimeException('Invalid tactic Raw path.');
        }

        return ['race_date' => $race->race_date->format('Y-m-d'), 'absolute_path' => $root.'/'.$relative,
            'source_hash' => $log->sha256, 'raw_response_size' => (int) $log->response_size,
            'fetch_hash' => $log->sha256, 'fetch_bytes' => (int) $log->response_size,
            'content_type' => $log->content_type, 'converted_hash' => null];
    }
}
