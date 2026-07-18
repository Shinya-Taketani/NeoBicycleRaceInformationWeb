<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class StoredRawResponseDto
{
    public function __construct(
        public string $rawFilePath,
        public string $sha256,
        public int $responseSize,
        public ?string $detectedEncoding,
        public bool $utf8ConversionSucceeded,
        public string $utf8Body,
        public ?int $fetchLogId,
    ) {}
}
