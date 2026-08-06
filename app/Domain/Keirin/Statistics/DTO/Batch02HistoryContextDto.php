<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Batch02HistoryContextDto
{
    /**
     * @param  list<HistoricalRaceDto>  $histories
     * @param  list<HistoricalRaceDto>  $preMeeting
     * @param  list<HistoricalRaceDto>  $inMeeting
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $qualityReasons
     */
    public function __construct(
        public array $histories,
        public array $preMeeting,
        public array $inMeeting,
        public string $historyInputHash,
        public array $evidence,
        public array $qualityReasons,
    ) {}
}
