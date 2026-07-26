<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\DTO\StatInputAsOfDto;
use App\Domain\Keirin\Statistics\Enums\StatInputAsOfPolicy;
use App\Models\Race;

final class StatInputAsOfResolver
{
    public function resolve(Race $race): StatInputAsOfDto
    {
        if ($race->sales_close_at !== null) {
            return new StatInputAsOfDto($race->sales_close_at, StatInputAsOfPolicy::SalesClose);
        }
        if ($race->scheduled_start_at !== null) {
            return new StatInputAsOfDto($race->scheduled_start_at, StatInputAsOfPolicy::StartTime);
        }

        return new StatInputAsOfDto(null, StatInputAsOfPolicy::Unavailable);
    }
}
