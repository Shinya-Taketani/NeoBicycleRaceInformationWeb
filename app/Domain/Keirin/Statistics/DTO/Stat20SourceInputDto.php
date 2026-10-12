<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;
use DateTimeZone;

readonly class Stat20SourceInputDto
{
    /** @param array<string,mixed> $features @param array<string,mixed> $evidence */
    public function __construct(
        public int $id,
        public int $runId,
        public string $statCode,
        public string $calculationVersion,
        public string $subjectType,
        public string $subjectKey,
        public int $raceId,
        public ?int $raceEntryId,
        public ?int $playerId,
        public ?int $bikeNumber,
        public string $status,
        public string $qualityStatus,
        public ?DateTimeImmutable $inputAsOf,
        public ?DateTimeImmutable $sourceFetchedAt,
        public array $features,
        public array $evidence,
        public string $inputHash,
    ) {}

    public function hashPayload(): array
    {
        return [
            'id' => $this->id, 'run_id' => $this->runId, 'stat_code' => $this->statCode,
            'calculation_version' => $this->calculationVersion,
            'subject_type' => $this->subjectType, 'subject_key' => $this->subjectKey,
            'race_id' => $this->raceId, 'race_entry_id' => $this->raceEntryId,
            'player_id' => $this->playerId, 'bike_number' => $this->bikeNumber,
            'status' => $this->status, 'quality_status' => $this->qualityStatus,
            'input_as_of' => self::timestamp($this->inputAsOf),
            'source_fetched_at' => self::timestamp($this->sourceFetchedAt),
            'features' => $this->features, 'evidence' => $this->evidence,
            'input_hash' => $this->inputHash,
        ];
    }

    public static function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }
}
