<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Scraping\DTO\RaceDistanceObservationDto;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Models\Race;
use App\Models\RaceDistanceObservation;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use App\Repositories\RaceDistanceObservationRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RaceDistanceObservationService
{
    public const VERSION = 'STAT38-PJ0315-PLANNED-OBSERVATION-v1';

    public function __construct(
        private readonly RawReader $raw,
        private readonly RaceDetailParser $parser,
        private readonly EmbeddedJsonExtractor $json,
        private readonly RaceDistanceObservationRepository $observations,
    ) {}

    public function import(int $raceId, int $fetchLogId, bool $dryRun): array
    {
        return DB::transaction(function () use ($raceId, $fetchLogId, $dryRun): array {
            $query = Race::query()->whereKey($raceId)->whereBetween('race_date', ['2022-01-01', '2025-12-31']);
            $race = ($dryRun ? $query : $query->lockForUpdate())->firstOrFail();
            $log = ScrapingFetchLog::query()->findOrFail($fetchLogId);
            $source = $this->source($race, $log);
            $dto = $this->prepare($race, $log, $this->raw->read($source));
            $result = $this->observations->append($dto, self::VERSION, $dryRun);
            $this->raw->verify($source);
            if ($this->metadataHash($log->fresh()) !== $dto->sourceMetadataHash) {
                throw new RuntimeException('Distance fetch metadata changed before publication.');
            }

            return [...$result, 'race_id' => $raceId, 'fetch_log_id' => $fetchLogId, 'dry_run' => $dryRun,
                'distance_status' => $dto->distance === null ? 'MISSING' : 'AVAILABLE',
                'laps_status' => $dto->laps === null ? 'MISSING' : 'AVAILABLE',
                'timing_status' => $dto->attributes(self::VERSION)['timing_status'],
                'prediction_use' => 'NOT_AUTHORIZED'];
        });
    }

    public function prepare(Race $race, ScrapingFetchLog $log, string $html): RaceDistanceObservationDto
    {
        $this->sourceIdentity($race, $log);
        $page = $this->parser->parse($html);
        $track = Racetrack::query()->findOrFail($race->racetrack_id);
        $date = $race->race_date->format('Y-m-d');
        if ($page->raceDate !== str_replace('-', '', $date) || $page->trackCode !== $track->external_track_id
            || $page->raceNumber !== (int) $race->race_number
            || $race->external_race_id !== sprintf('%s:%s:%02d', $page->trackCode, $page->raceDate, $page->raceNumber)) {
            throw new RuntimeException('PJ0315 distance race/date/track/number mismatch.');
        }
        $detail = $this->json->extract($html, 'PC0201')['C0201data']['C0201racedtl'];
        $fields = [];
        foreach (['kyori', 'syukai'] as $key) {
            $fields[$key] = ['present' => array_key_exists($key, $detail), 'value' => $detail[$key] ?? null];
        }
        $start = $page->startTime === null ? null : DateTimeImmutable::createFromFormat('!Ymd H:i', $page->raceDate.' '.$page->startTime, new DateTimeZone('Asia/Tokyo'));
        if ($start === false) {
            throw new RuntimeException('Invalid same-Raw scheduled start.');
        }

        return new RaceDistanceObservationDto((int) $race->id, $date, $page->trackCode, $page->raceNumber,
            (int) $log->id, $page->distance, $page->laps, $log->fetched_at?->toDateTimeImmutable(), $start,
            $log->request_url, $log->raw_file_path, $log->sha256, (int) $log->response_size,
            hash('sha256', $html), $this->metadataHash($log), $log->parser_version, $fields,
            array_map(fn ($entry) => ['bike_number' => $entry->bikeNumber, 'external_player_id' => $entry->externalPlayerId], $page->entries),
            $log->created_at?->toDateTimeImmutable());
    }

    public function verifyStored(RaceDistanceObservation $observation): void
    {
        if ($observation->parser_version !== self::VERSION) {
            throw new RuntimeException('Unsupported distance observation version.');
        }
        $this->import((int) $observation->race_id, (int) $observation->scraping_fetch_log_id, true);
    }

    private function sourceIdentity(Race $race, ScrapingFetchLog $log): void
    {
        $date = $race->race_date->format('Y-m-d');
        $expected = rtrim((string) config('keirin.base_url'), '/').config('keirin.routes.race_live');
        $params = $log->request_parameters;
        if ($date < '2022-01-01' || $date > '2025-12-31' || $race->source !== config('keirin.source')
            || $log->source !== $race->source || $log->request_method !== 'POST' || $log->request_url !== $expected
            || (int) $log->http_status !== 200 || $log->error_type !== null || ! $log->utf8_conversion_succeeded
            || ! is_array($params) || ($params['disp'] ?? null) !== 'PJ0315'
            || ! is_string($params['encp'] ?? null) || $params['encp'] === ''
            || ! is_string($log->parser_version) || $log->parser_version === '') {
            throw new RuntimeException('Invalid/unsupported PJ0315 distance source or target year.');
        }
    }

    private function source(Race $race, ScrapingFetchLog $log): array
    {
        $this->sourceIdentity($race, $log);
        $relative = $log->raw_file_path;
        $root = realpath(Storage::disk(config('keirin.raw_disk'))->path(''));
        if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')
            || str_contains($relative, "\0") || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//~', $relative) === 1 || $root === false) {
            throw new RuntimeException('Invalid distance Raw path.');
        }

        return ['race_date' => $race->race_date->format('Y-m-d'), 'absolute_path' => $root.'/'.$relative,
            'source_hash' => $log->sha256, 'raw_response_size' => (int) $log->response_size,
            'fetch_hash' => $log->sha256, 'fetch_bytes' => (int) $log->response_size,
            'converted_hash' => null, 'content_type' => $log->content_type];
    }

    private function metadataHash(ScrapingFetchLog $log): string
    {
        return hash('sha256', json_encode(array_intersect_key($log->getAttributes(), array_flip([
            'id', 'source', 'request_method', 'request_url', 'request_parameters', 'http_status', 'fetched_at',
            'content_type', 'utf8_conversion_succeeded', 'response_size', 'sha256', 'raw_file_path', 'parser_version', 'error_type', 'created_at',
        ])), JSON_THROW_ON_ERROR));
    }
}
