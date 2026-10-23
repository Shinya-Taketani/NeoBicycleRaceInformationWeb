<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\RosterSnapshots\Reader;
use App\Domain\Keirin\Scraping\RosterSnapshots\UnsupportedCategory;
use App\Domain\Keirin\Statistics\Calculators\Stat46RosterDifferenceCalculator;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Models\Race;
use App\Models\RaceRosterSnapshot;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use App\Repositories\RaceRosterSnapshotRepository;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RaceRosterSnapshotService
{
    public function __construct(
        private readonly RawReader $raw,
        private readonly Reader $reader,
        private readonly RaceRosterSnapshotRepository $snapshots,
        private readonly DeterministicJsonHasher $hasher,
        private readonly Stat46RosterDifferenceCalculator $calculator,
    ) {}

    public function import(int $raceId, int $fetchLogId, bool $dryRun): array
    {
        return DB::transaction(function () use ($raceId, $fetchLogId, $dryRun): array {
            $query = $this->developmentRace($raceId);
            $race = ($dryRun ? $query : $query->lockForUpdate())->firstOrFail();
            [$dto, $source] = $this->read($race, ScrapingFetchLog::query()->findOrFail($fetchLogId));
            if ($dto instanceof UnsupportedCategory) {
                return [...$dto->result(), 'race_id' => $raceId, 'fetch_log_id' => $fetchLogId,
                    'snapshot_id' => null, 'planned_new_snapshots' => 0, 'dry_run' => $dryRun,
                    'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false];
            }
            $result = $this->snapshots->append($dto, $dryRun);
            $this->verifyEnd($race, $dto->attributes, $source);

            return [...$result, 'race_id' => $raceId, 'fetch_log_id' => $fetchLogId, 'dry_run' => $dryRun,
                'entrant_count' => $dto->attributes['entrant_count'], 'timing_status' => $dto->attributes['timing_status'],
                'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false];
        });
    }

    public function compare(int $raceId, int $firstId, int $secondId, ?DateTimeImmutable $inputAsOf): array
    {
        $race = $this->developmentRace($raceId)->firstOrFail();
        $captures = [];
        foreach (array_unique([$firstId, $secondId]) as $id) {
            $row = RaceRosterSnapshot::query()->where('race_id', $raceId)->findOrFail($id);
            [$dto, $source] = $this->read($race, ScrapingFetchLog::query()->findOrFail($row->scraping_fetch_log_id));
            if ($dto instanceof UnsupportedCategory) {
                throw $dto;
            }
            $this->snapshots->verify($row, $dto);
            $this->verifyEnd($race, $dto->attributes, $source);
            $captures[] = [$dto, $source];
        }
        $result = $this->calculator->calculate($captures[0][0], $captures[1][0] ?? null, $inputAsOf);
        foreach ($captures as [$dto, $source]) {
            $this->verifyEnd($race, $dto->attributes, $source);
        }

        return $result;
    }

    private function developmentRace(int $raceId): Builder
    {
        // Execution authorization is intentionally narrower than the storage/Calculator contract.
        return Race::query()->whereKey($raceId)->whereBetween('race_date', ['2022-01-01', '2025-12-31']);
    }

    /** @return array{RaceRosterSnapshotDto|UnsupportedCategory, array} */
    private function read(Race $race, ScrapingFetchLog $log): array
    {
        $expectedUrl = rtrim((string) config('keirin.base_url'), '/').config('keirin.routes.race_live');
        $params = $log->request_parameters;
        if ($race->source !== config('keirin.source') || $log->source !== $race->source
            || $log->request_method !== 'POST' || $log->request_url !== $expectedUrl || (int) $log->http_status !== 200
            || $log->error_type !== null || ! $log->utf8_conversion_succeeded
            || ! is_array($params) || ($params['disp'] ?? null) !== 'PJ0315'
            || ! is_string($params['encp'] ?? null) || $params['encp'] === ''
            || ! is_string($log->parser_version) || $log->parser_version === '') {
            throw new RuntimeException('Invalid PJ0315 roster source.');
        }
        $path = $log->raw_file_path;
        $root = realpath(Storage::disk(config('keirin.raw_disk'))->path(''));
        if ($root === false || ! is_string($path) || $path === '' || str_starts_with($path, '/')
            || str_contains($path, '\\') || str_contains($path, "\0") || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//~', $path) === 1) {
            throw new RuntimeException('Invalid roster Raw path.');
        }
        $source = ['race_date' => $race->race_date->format('Y-m-d'), 'absolute_path' => $root.'/'.$path,
            'source_hash' => $log->sha256, 'raw_response_size' => (int) $log->response_size,
            'fetch_hash' => $log->sha256, 'fetch_bytes' => (int) $log->response_size,
            'converted_hash' => null, 'content_type' => $log->content_type];
        $html = $this->raw->read($source);
        $expected = $this->raceIdentity($race);
        $capture = ['scraping_fetch_log_id' => (int) $log->id,
            'fetched_at' => $log->fetched_at?->format('Y-m-d\TH:i:s.uP'),
            'fetch_log_saved_at' => $log->created_at?->format('Y-m-d\TH:i:s.uP'),
            'source_url' => $log->request_url, 'raw_file_path' => $path, 'raw_sha256' => $log->sha256,
            'raw_bytes' => (int) $log->response_size, 'converted_sha256' => hash('sha256', $html),
            'source_metadata_hash' => $this->metadataHash($log), 'source_parser_version' => $log->parser_version];

        try {
            return [$this->reader->project($html, $expected, $capture, $race->race_type), $source];
        } catch (UnsupportedCategory $skipped) {
            $this->verifyEnd($race, [...$expected, ...$capture], $source);

            return [$skipped, $source];
        }
    }

    private function raceIdentity(Race $race): array
    {
        $track = Racetrack::query()->findOrFail($race->racetrack_id);
        $date = $race->race_date->format('Y-m-d');
        if ($race->source !== config('keirin.source')
            || $race->external_race_id !== sprintf('%s:%s:%02d', $track->external_track_id, str_replace('-', '', $date), $race->race_number)) {
            throw new RuntimeException('Invalid roster expected race identity.');
        }

        return ['race_id' => (int) $race->id, 'race_date' => $date,
            'track_code' => $track->external_track_id, 'race_number' => (int) $race->race_number];
    }

    private function verifyEnd(Race $race, array $attributes, array $source): void
    {
        $this->raw->verify($source);
        $freshRace = $race->fresh();
        if ($this->metadataHash(ScrapingFetchLog::query()->findOrFail($attributes['scraping_fetch_log_id'])) !== $attributes['source_metadata_hash']
            || $this->raceIdentity($freshRace) !== array_intersect_key($attributes, array_flip(['race_id', 'race_date', 'track_code', 'race_number']))
            || $freshRace->race_type !== $race->race_type) {
            throw new RuntimeException('Roster metadata changed before publication.');
        }
    }

    private function metadataHash(ScrapingFetchLog $log): string
    {
        return $this->hasher->hash(array_intersect_key($log->getAttributes(), array_flip([
            'id', 'source', 'request_method', 'request_url', 'request_parameters', 'http_status', 'fetched_at',
            'content_type', 'utf8_conversion_succeeded', 'response_size', 'sha256', 'raw_file_path', 'parser_version', 'error_type', 'created_at',
        ])));
    }
}
