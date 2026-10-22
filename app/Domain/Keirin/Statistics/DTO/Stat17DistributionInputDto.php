<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Stat17DistributionInputDto
{
    public function __construct(public array $observation, public DateTimeImmutable $inputAsOf) {}
}
