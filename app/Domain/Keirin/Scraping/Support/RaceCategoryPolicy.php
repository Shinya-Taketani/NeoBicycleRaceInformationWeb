<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;

class RaceCategoryPolicy
{
    public function classify(?string $raceType): RaceCategory
    {
        if ($raceType === null) {
            return RaceCategory::Unknown;
        }

        $normalized = mb_convert_kana($raceType, 'as', 'UTF-8');

        if (str_contains($normalized, 'ガールズ') || str_contains($normalized, 'L級')) {
            return RaceCategory::Girls;
        }

        if (str_contains($normalized, 'S級') || str_contains($normalized, 'A級')) {
            return RaceCategory::Men;
        }

        return RaceCategory::Unknown;
    }
}
