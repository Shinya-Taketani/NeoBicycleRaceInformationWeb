<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

final readonly class StoredCapture
{
    public function __construct(public string $path, public string $manifestSha256, public array $validation) {}
}
