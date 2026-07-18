<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Exceptions;

use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use RuntimeException;

class InvalidRaceResultStatusTransitionException extends RuntimeException
{
    public function __construct(RaceResultStatus $current, RaceResultStatus $requested)
    {
        parent::__construct("Race result status transition from {$current->value} to {$requested->value} is not allowed.");
    }
}
