<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

final readonly class Stat20AcquiredHistoryInputDto
{
    public function __construct(public Stat20RaceInputDto $race, public array $sourceRunAudits) {}
}
