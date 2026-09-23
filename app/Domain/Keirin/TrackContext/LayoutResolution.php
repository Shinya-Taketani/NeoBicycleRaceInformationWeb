<?php

declare(strict_types=1);

namespace App\Domain\Keirin\TrackContext;

final readonly class LayoutResolution
{
    public function __construct(
        public string $status,
        public string $masterVersion,
        public string $source,
        public string $externalTrackId,
        public string $raceDate,
        public ?array $layout = null,
        public ?array $definition = null,
        public array $candidateVersions = [],
    ) {}
}
