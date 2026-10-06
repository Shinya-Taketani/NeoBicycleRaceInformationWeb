<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison;

use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use InvalidArgumentException;

final class FieldBikeProjector
{
    public static function category(int $count, int $bike): string
    {
        if ($count < 5 || $count > 9 || $bike < 1 || $bike > 9) {
            throw new InvalidArgumentException('Invalid card count/bike category.');
        }

        return 'N'.$count.'_B'.$bike;
    }

    public static function race(array $race, int $year): array
    {
        Validator::race($race, $year);
        $count = count($race['entries']);
        foreach ($race['entries'] as &$entry) {
            $entry['signals'] = [...$entry['signals'], ...$entry['history'], self::category($count, $entry['bike'])];
            unset($entry['history'], $entry['history_status']);
        }
        unset($entry);

        return $race;
    }
}
