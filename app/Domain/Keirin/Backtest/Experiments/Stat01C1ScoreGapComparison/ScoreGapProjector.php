<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat01C1ScoreGapComparison;

use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use RuntimeException;

final class ScoreGapProjector
{
    /** @return list<array{year:int,race_id:int,entry_id:int,bike:int,raw:int|float,mean:float,gap:float,entrant_count:int}> */
    public static function project(array $race, int $year): array
    {
        Validator::race($race, $year);
        $scores = array_map(static fn (array $entry): float => (float) $entry['raw'], $race['entries']);
        // Match the frozen C1 validator's binary64 arithmetic and saved entrant order.
        $sum = array_sum($scores);
        $mean = $sum / count($scores);
        if (! is_finite($sum) || ! is_finite($mean)) {
            throw new RuntimeException('Race score sum/mean was non-finite.');
        }
        $rows = [];
        foreach ($race['entries'] as $i => $entry) {
            $gap = $scores[$i] - $mean;
            if (! is_finite($gap)) {
                throw new RuntimeException('Race score gap was non-finite.');
            }
            $rows[] = ['year' => $year, 'race_id' => $race['race_id'], 'entry_id' => $entry['id'],
                'bike' => $entry['bike'], 'raw' => $entry['raw'], 'mean' => $mean,
                'gap' => $gap === 0.0 ? 0.0 : $gap, 'entrant_count' => count($scores)];
        }

        return $rows;
    }
}
