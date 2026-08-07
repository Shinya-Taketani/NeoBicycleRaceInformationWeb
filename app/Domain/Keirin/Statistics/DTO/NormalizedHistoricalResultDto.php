<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;

readonly class NormalizedHistoricalResultDto
{
    public function __construct(
        public HistoricalResultState $state,
        public bool $tied,
    ) {}
}
