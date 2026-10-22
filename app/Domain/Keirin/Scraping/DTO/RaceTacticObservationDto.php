<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use InvalidArgumentException;

readonly class RaceTacticObservationDto
{
    public const FIELDS = [
        'nigeCnt' => ['escape_count', 'escapeCount'], 'makuriCnt' => ['sprint_count', 'sprintCount'],
        'sasiCnt' => ['overtake_count', 'overtakeCount'], 'markCnt' => ['mark_count', 'markCount'],
        'backCnt' => ['back_count', 'backCount'], 'homeTori' => ['home_count', 'homeCount'], 'stTori' => ['start_count', 'startCount'],
    ];

    public function __construct(
        public RaceDistanceObservationDto $capture,
        public int $entryId,
        public ?int $playerId,
        public int $bikeNumber,
        public string $externalPlayerId,
        public array $counts,
        public array $rawFields,
    ) {
        if ($entryId < 1 || ($playerId !== null && $playerId < 1) || $bikeNumber < 1 || $bikeNumber > 9
            || preg_match('/^[0-9]{6}$/D', $externalPlayerId) !== 1
            || array_keys($counts) !== array_column(self::FIELDS, 0) || array_keys($rawFields) !== array_keys(self::FIELDS)) {
            throw new InvalidArgumentException('Invalid tactic observation identity/fields.');
        }
        foreach ($counts as $value) {
            if ($value !== null && (! is_int($value) || $value < 0 || $value > 9999)) {
                throw new InvalidArgumentException('Invalid displayed tactic count.');
            }
        }
    }

    public function attributes(string $version): array
    {
        $source = $this->capture->attributes($version);

        return array_intersect_key($source, array_flip(['race_id', 'race_date', 'track_code', 'race_number',
            'scraping_fetch_log_id', 'scheduled_start_at', 'fetched_at', 'timing_status', 'source_url', 'raw_file_path',
            'raw_sha256', 'raw_bytes', 'converted_sha256', 'source_metadata_hash', 'source_parser_version', 'parser_version']))
            + ['race_entry_id' => $this->entryId, 'player_id' => $this->playerId, 'bike_number' => $this->bikeNumber,
                'external_player_id' => $this->externalPlayerId, ...$this->counts, 'raw_fields' => $this->rawFields,
                'fetch_log_saved_at' => $this->capture->savedAt, 'aggregation_period_status' => 'UNKNOWN',
                'aggregation_basis_status' => 'UNKNOWN', 'official_publication_at' => null, 'use_class' => 'RETROSPECTIVE_ONLY',
                'metadata' => ['origin' => 'SAVED_PJ0315_RAW', 'semantic' => 'DISPLAYED_COUNTS_NOT_RACE_ACTIONS',
                    'official_publication_status' => 'UNKNOWN',
                    'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED']];
    }
}
