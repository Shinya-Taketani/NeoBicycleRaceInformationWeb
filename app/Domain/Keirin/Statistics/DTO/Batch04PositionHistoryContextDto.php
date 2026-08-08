<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Batch04PositionHistoryContextDto
{
    /**
     * @param  array<string, array<string, mixed>|null>  $groups
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public array $groups,
        public string $historyInputHash,
        public array $evidence,
    ) {}
}
