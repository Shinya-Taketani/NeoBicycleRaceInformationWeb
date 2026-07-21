<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Exceptions;

use RuntimeException;

class RaceDayMetadataUnavailableException extends RuntimeException
{
    public const REASON_RACE_MEETING_CANCELLED = 'RACE_MEETING_CANCELLED';

    /** @param array<string, mixed> $evidence */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $evidence,
    ) {
        parent::__construct($message);
    }
}
