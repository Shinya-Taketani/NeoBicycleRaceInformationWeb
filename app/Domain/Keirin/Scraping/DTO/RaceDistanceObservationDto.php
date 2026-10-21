<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

readonly class RaceDistanceObservationDto
{
    public function __construct(
        public int $raceId,
        public string $raceDate,
        public string $trackCode,
        public int $raceNumber,
        public int $fetchLogId,
        public ?int $distance,
        public ?int $laps,
        public ?DateTimeImmutable $fetchedAt,
        public ?DateTimeImmutable $scheduledStartAt,
        public string $sourceUrl,
        public string $rawFilePath,
        public string $rawSha256,
        public int $rawBytes,
        public string $convertedSha256,
        public string $sourceMetadataHash,
        public string $sourceParserVersion,
        public array $rawFields,
        public array $entrants,
        public ?DateTimeImmutable $savedAt = null,
    ) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raceDate);
        if ($raceId < 1 || $fetchLogId < 1 || $date === false || $date->format('Y-m-d') !== $raceDate
            || $raceDate < '2022-01-01' || $raceDate > '2025-12-31' || $raceNumber < 1 || $raceNumber > 12
            || preg_match('/^\d{2}$/D', $trackCode) !== 1 || $rawBytes < 1 || $rawBytes > 4194304
            || ($distance !== null && ($distance < 1 || $distance > 10000))
            || ($laps !== null && ($laps < 1 || $laps > 100))
            || $sourceParserVersion === '' || $entrants === [] || count($entrants) > 9) {
            throw new InvalidArgumentException('Invalid distance observation identity or values.');
        }
        foreach ([$rawSha256, $convertedSha256, $sourceMetadataHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new InvalidArgumentException('Invalid distance observation seal.');
            }
        }
        $bikes = $players = [];
        foreach ($entrants as $entry) {
            $bike = $entry['bike_number'] ?? null;
            $player = $entry['external_player_id'] ?? null;
            if (! is_int($bike) || $bike < 1 || $bike > 9 || ! is_string($player)
                || preg_match('/^\d{6}$/D', $player) !== 1 || isset($bikes[$bike]) || isset($players[$player])) {
                throw new InvalidArgumentException('Invalid/duplicate distance entrant identity.');
            }
            $bikes[$bike] = $players[$player] = true;
        }
    }

    public function attributes(string $version): array
    {
        return [
            'race_id' => $this->raceId, 'race_date' => $this->raceDate, 'track_code' => $this->trackCode,
            'race_number' => $this->raceNumber, 'scraping_fetch_log_id' => $this->fetchLogId,
            'planned_distance_m' => $this->distance, 'planned_laps' => $this->laps,
            'distance_status' => $this->distance === null ? 'MISSING' : 'AVAILABLE',
            'laps_status' => $this->laps === null ? 'MISSING' : 'AVAILABLE',
            'actual_distance_m' => null, 'actual_laps' => null,
            'scheduled_start_at' => $this->scheduledStartAt, 'fetched_at' => $this->fetchedAt,
            'timing_status' => $this->fetchedAt === null || $this->scheduledStartAt === null ? 'UNKNOWN'
                : ($this->fetchedAt < $this->scheduledStartAt ? 'BEFORE_SAME_RAW_START' : 'AT_OR_AFTER_SAME_RAW_START'),
            'source_url' => $this->sourceUrl, 'raw_file_path' => $this->rawFilePath,
            'raw_sha256' => $this->rawSha256, 'raw_bytes' => $this->rawBytes,
            'converted_sha256' => $this->convertedSha256, 'source_metadata_hash' => $this->sourceMetadataHash,
            'source_parser_version' => $this->sourceParserVersion, 'parser_version' => $version,
            'raw_fields' => $this->rawFields, 'entrants' => $this->entrants,
            'metadata' => ['semantic' => 'OFFICIAL_PLANNED_DISTANCE_AND_LAPS', 'origin' => 'SAVED_PJ0315_RAW',
                'official_publication_time' => 'UNKNOWN', 'historical_as_of_available' => false,
                'fetch_log_created_at' => $this->savedAt?->format(DATE_ATOM),
                'prediction_use' => 'NOT_AUTHORIZED', 'actual_values' => 'NOT_OBSERVED'],
        ];
    }
}
