<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class ConvertedBodyDto
{
    public function __construct(
        public string $utf8Body,
        public string $detectedEncoding,
        public string $sha256,
    ) {}
}
