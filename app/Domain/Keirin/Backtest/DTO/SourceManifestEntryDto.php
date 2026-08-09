<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class SourceManifestEntryDto
{
    public function __construct(
        public int $year,
        public int $featureRunId,
        public string $featureRunUuid,
        public string $targetFrom,
        public string $targetTo,
        public int $expectedRaceCount,
        public int $expectedResultCount,
    ) {}

    /** @return array<string, int|string> */
    public function canonical(): array
    {
        return [
            'year' => $this->year,
            'feature_run_id' => $this->featureRunId,
            'feature_run_uuid' => $this->featureRunUuid,
            'target_from' => $this->targetFrom,
            'target_to' => $this->targetTo,
            'expected_race_count' => $this->expectedRaceCount,
            'expected_result_count' => $this->expectedResultCount,
        ];
    }
}
