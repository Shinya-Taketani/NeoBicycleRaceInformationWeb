<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Support\Stat20AcquiredHistoryContract;

final readonly class Stat20AcquiredHistoryInputDto
{
    public function __construct(public Stat20RaceInputDto $race, public array $sourceRunAudits,
        public string $sourceContract = Stat20AcquiredHistoryContract::VERSION)
    {
        Stat20AcquiredHistoryContract::versions($sourceContract);
    }
}
