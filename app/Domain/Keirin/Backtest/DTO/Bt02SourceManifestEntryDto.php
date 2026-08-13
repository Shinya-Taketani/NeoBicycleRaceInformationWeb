<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02SourceManifestEntryDto
{
    public function __construct(
        public int $year,
        public string $statCode,
        public int $featureRunId,
        public string $featureRunUuid,
        public string $calculationVersion,
        public int $sourceStat01RunId,
        public string $sourceStat01RunUuid,
        public string $targetFrom,
        public string $targetTo,
        public ?string $historyFrom,
        public string $subjectType,
        public int $processedRaceCount,
        public int $targetEntryCount,
        public int $rowCount,
        public string $sourceFingerprintSha256,
        public string $contentFingerprintSha256,
    ) {}

    /** @return array<string, int|string|null> */
    public function canonical(): array
    {
        return [
            'year' => $this->year,
            'stat_code' => $this->statCode,
            'feature_run_id' => $this->featureRunId,
            'feature_run_uuid' => $this->featureRunUuid,
            'calculation_version' => $this->calculationVersion,
            'source_stat01_run_id' => $this->sourceStat01RunId,
            'source_stat01_run_uuid' => $this->sourceStat01RunUuid,
            'target_from' => $this->targetFrom,
            'target_to' => $this->targetTo,
            'history_from' => $this->historyFrom,
            'subject_type' => $this->subjectType,
            'row_count' => $this->rowCount,
            'source_fingerprint_sha256' => $this->sourceFingerprintSha256,
            'content_fingerprint_sha256' => $this->contentFingerprintSha256,
        ];
    }
}
