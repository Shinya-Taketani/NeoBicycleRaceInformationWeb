<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use DateTimeImmutable;

readonly class PlayerDetailDto
{
    /**
     * @param  list<PlayerGradeHistoryDto>  $gradeHistories
     */
    public function __construct(
        public string $externalPlayerId,
        public string $name,
        public ?string $nameKana,
        public ?string $prefecture,
        public ?DateTimeImmutable $birthDate,
        public ?string $gender,
        public ?string $registrationNumber,
        public ?string $graduationPeriod,
        public ?string $grade,
        public ?DateTimeImmutable $gradeAssignedOn,
        public ?string $nextGrade,
        public ?string $ridingStyle,
        public ?float $currentScore,
        public ?PlayerStatsDto $recentStats,
        public array $gradeHistories,
        public ?DateTimeImmutable $sourceUpdatedAt,
        public string $sourceUrl,
    ) {}
}
