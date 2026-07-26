<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatFeatureValueType: string
{
    case Integer = 'INTEGER';
    case Numeric = 'NUMERIC';
    case Text = 'TEXT';
    case Boolean = 'BOOLEAN';
    case Json = 'JSON';
}
