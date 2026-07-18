<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum RaceCategory: string
{
    case Men = 'MEN';
    case Girls = 'GIRLS';
    case Unknown = 'UNKNOWN';
}
