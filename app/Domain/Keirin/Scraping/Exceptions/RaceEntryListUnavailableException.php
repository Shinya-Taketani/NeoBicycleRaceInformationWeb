<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Exceptions;

use RuntimeException;

class RaceEntryListUnavailableException extends RuntimeException
{
    public const REASON_RACE_DAY_CANCELLED = 'RACE_DAY_CANCELLED';

    public const REASON_RACE_DAY_POSTPONED = 'RACE_DAY_POSTPONED';

    /** @param array<string, mixed> $evidence */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $evidence,
    ) {
        parent::__construct($message);
    }
}
