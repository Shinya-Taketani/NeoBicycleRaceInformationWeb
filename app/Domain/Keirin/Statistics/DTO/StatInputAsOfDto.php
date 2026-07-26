<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatInputAsOfPolicy;
use DateTimeImmutable;

final readonly class StatInputAsOfDto
{
    public function __construct(
        public ?DateTimeImmutable $value,
        public StatInputAsOfPolicy $policy,
    ) {}
}
