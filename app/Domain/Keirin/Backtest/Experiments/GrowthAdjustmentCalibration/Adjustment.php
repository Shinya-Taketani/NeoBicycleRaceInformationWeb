<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use RuntimeException;

final class Adjustment
{
    public function __construct(private readonly Predictor $predictor) {}

    public function apply(array $race, array $growth, int $k): array
    {
        Contract::race($race);
        if ($k < -50 || $k > 50 || array_keys($race) !== ['year', 'race_id', 'entries'] || count($growth) !== count($race['entries'])) {
            throw new RuntimeException('Adjustment candidate/input was invalid.');
        }
        foreach ($race['entries'] as $i => &$entry) {
            if (array_diff(array_keys($entry), ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'bins']) !== []) {
                throw new RuntimeException('Adjustment input contained outcomes.');
            }
            $g = $growth[$i];
            Projection::validate($g);
            if ($g['entry_id'] !== $entry['id'] || $g['race_id'] !== $race['race_id'] || $g['year'] !== $race['year']) {
                throw new RuntimeException('Growth/input identities differed.');
            }
            if ($k !== 0 && $g['score_point'] !== null && $g['score_point'] !== 0) {
                $entry['anchor'] += ($k / 100) * $g['score_point'];
            }
        }
        unset($entry);

        return $race;
    }

    public function predict(array $race, array $growth, int $k, Bt03e03FitResultDto $fit): array
    {
        return $this->predictor->predict($this->apply($race, $growth, $k), $fit);
    }

    public static function primary(array $prediction): array
    {
        return array_map(fn ($i) => $prediction['decision']['primary_position_'.$i.'_bike'], [1, 2, 3]);
    }
}
