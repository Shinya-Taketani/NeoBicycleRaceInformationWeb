<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Batch03HistoryContextDto
{
    /**
     * @param  list<Batch03HistoricalRaceDto>  $histories
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public array $histories,
        public string $targetContextHash,
        public string $historyInputHash,
        public array $evidence,
    ) {}
}
