<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

final readonly class ArtifactCalculationSource
{
    public function __construct(public array $sourceRef, public string $executionUuid) {}
}
