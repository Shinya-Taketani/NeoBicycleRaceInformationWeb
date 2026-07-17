<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use DateTimeImmutable;

readonly class FetchedResponseDto
{
    public function __construct(
        public string $source,
        public string $method,
        public string $url,
        public string $requestKey,
        public ?int $httpStatus,
        public string $body,
        public ?string $contentType,
        public DateTimeImmutable $fetchedAt,
        public int $retryCount = 0,
    ) {}
}
