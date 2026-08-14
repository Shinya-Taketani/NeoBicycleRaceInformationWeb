<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02BaselineFingerprintEntryDto
{
    public function __construct(
        public int $year,
        public int $featureRunId,
        public string $featureRunUuid,
        public string $calculationVersion,
        public string $targetFrom,
        public string $targetTo,
        public int $raceCount,
        public int $rowCount,
        public string $sourceFingerprintSha256,
        public string $contentFingerprintSha256,
    ) {}

    /** @return array<string, int|string> */
    public function canonical(): array
    {
        return [
            'year' => $this->year,
            'feature_run_id' => $this->featureRunId,
            'feature_run_uuid' => $this->featureRunUuid,
            'calculation_version' => $this->calculationVersion,
            'target_from' => $this->targetFrom,
            'target_to' => $this->targetTo,
            'race_count' => $this->raceCount,
            'row_count' => $this->rowCount,
            'source_fingerprint_sha256' => $this->sourceFingerprintSha256,
            'content_fingerprint_sha256' => $this->contentFingerprintSha256,
        ];
    }
}
