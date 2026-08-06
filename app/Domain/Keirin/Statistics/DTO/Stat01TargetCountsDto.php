<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Stat01TargetCountsDto
{
    public function __construct(
        public int $races,
        public int $entries,
    ) {}
}
