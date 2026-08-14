<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use RuntimeException;

readonly class Bt02OutcomeSnapshotPartitionDto
{
    public function __construct(
        public int $year,
        public string $file,
        public int $raceCount,
        public int $resultRowCount,
        public int $byteCount,
        public string $sha256,
    ) {
        if (! in_array($year, [2022, 2023, 2024, 2025], true)
            || $file !== "{$year}.jsonl"
            || $raceCount < 0 || $resultRowCount < 0 || $byteCount < 0
            || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException('BT-02 outcome snapshot partition metadata was invalid.');
        }
    }

    /** @return array{year: int, file: string, race_count: int, result_row_count: int, byte_count: int, sha256: string} */
    public function canonical(): array
    {
        return [
            'year' => $this->year,
            'file' => $this->file,
            'race_count' => $this->raceCount,
            'result_row_count' => $this->resultRowCount,
            'byte_count' => $this->byteCount,
            'sha256' => $this->sha256,
        ];
    }
}
