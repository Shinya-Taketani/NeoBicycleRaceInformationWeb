<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class VerifiedSourceDto
{
    public function __construct(
        public SourceManifestEntryDto $manifest,
        public int $verifiedRaceCount,
        public int $verifiedResultCount,
    ) {}
}
