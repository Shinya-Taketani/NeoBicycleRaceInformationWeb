<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum Batch04Stat: string
{
    case Stat39 = 'STAT-39';
    case Stat42 = 'STAT-42';

    public function calculationVersion(): string
    {
        return $this->value.'-existing-db-v1';
    }
}
