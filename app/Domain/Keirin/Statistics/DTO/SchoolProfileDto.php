<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

final readonly class SchoolProfileDto
{
    public function __construct(public string $externalPlayerId, public ?int $graduationPeriod,
        public ?int $schoolRank, public ?float $school200mSeconds, public ?DateTimeImmutable $debutDate,
        public array $originalFields) {}
}
