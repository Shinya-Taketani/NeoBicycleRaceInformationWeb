<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03BinSpoolIdentityDto
{
    public function __construct(
        public string $foldCode,
        public string $statCode,
        public string $cohortCode,
        public string $labelCode,
        public int $binIndex,
    ) {}

    /** @return array{fold_code: string, stat_code: string, cohort_code: string, label_code: string, bin_index: int} */
    public function canonical(): array
    {
        return [
            'fold_code' => $this->foldCode,
            'stat_code' => $this->statCode,
            'cohort_code' => $this->cohortCode,
            'label_code' => $this->labelCode,
            'bin_index' => $this->binIndex,
        ];
    }
}
