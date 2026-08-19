<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03CenteredSpoolIdentityDto
{
    public function __construct(
        public string $foldCode,
        public string $statCode,
        public string $cohortCode,
        public string $labelCode,
    ) {}

    /** @return array{fold_code: string, stat_code: string, cohort_code: string, label_code: string} */
    public function canonical(): array
    {
        return [
            'fold_code' => $this->foldCode,
            'stat_code' => $this->statCode,
            'cohort_code' => $this->cohortCode,
            'label_code' => $this->labelCode,
        ];
    }
}
