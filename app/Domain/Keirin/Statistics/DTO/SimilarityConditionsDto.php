<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

final readonly class SimilarityConditionsDto
{
    public function __construct(public array $values, public array $raw, public array $sources, public array $reasons) {}
}
