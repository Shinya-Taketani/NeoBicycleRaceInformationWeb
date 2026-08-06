<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum Batch02Stat: string
{
    case Stat10 = 'STAT-10';
    case Stat11 = 'STAT-11';
    case Stat12 = 'STAT-12';
    case Stat24 = 'STAT-24';
    case Stat26 = 'STAT-26';

    public function calculationVersion(): string
    {
        return $this->value.'-existing-db-v1';
    }
}
