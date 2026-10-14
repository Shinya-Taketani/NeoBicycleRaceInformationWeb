<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

final readonly class StoredAttributeEvidence
{
    public function __construct(
        public string $path,
        public string $bundleId,
        public string $sourceManifestSha256,
        public string $manifestSha256,
        public string $receiptSha256,
        public array $verification,
    ) {}
}
