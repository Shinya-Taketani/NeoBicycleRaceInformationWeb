<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class StoredImportedRawFileDto
{
    public function __construct(
        public string $rawFilePath,
        public string $sha256,
        public int $responseSize,
        public string $originalBody,
    ) {}
}
