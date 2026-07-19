<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

use App\Domain\Keirin\Scraping\Exceptions\ParserException;

class RaceEntrantCountPolicy
{
    /** @var list<int> */
    private const SUPPORTED_COUNTS = [5, 6, 7, 8, 9];

    public function assertSupported(int $count, string $context): void
    {
        if (! in_array($count, self::SUPPORTED_COUNTS, true)) {
            throw new ParserException("{$context} entrant count {$count} was not supported.");
        }
    }
}
