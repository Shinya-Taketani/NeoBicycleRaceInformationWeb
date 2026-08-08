<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum Batch03Stat: string
{
    case Stat07 = 'STAT-07';
    case Stat08 = 'STAT-08';
    case Stat23 = 'STAT-23';
    case Stat31 = 'STAT-31';
    case Stat32 = 'STAT-32';
    case Stat33 = 'STAT-33';

    public function calculationVersion(): string
    {
        return $this->value.'-existing-db-v1';
    }
}
