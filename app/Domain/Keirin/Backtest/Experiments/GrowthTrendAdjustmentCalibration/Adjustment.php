<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use RuntimeException;

final class Adjustment
{
    public function __construct(private readonly Predictor $predictor) {}

    public function apply(array $race, array $growth, int $k, float $scale): array
    {
        Contract::year($race['year']);
        OuterSource::keys($race, ['year', 'race_id', 'entries']);
        if (! is_int($race['race_id']) || $race['race_id'] < 1 || $k < -50 || $k > 50 || count($growth) !== count($race['entries'])) {
            throw new RuntimeException('Invalid adjustment input.');
        }
        foreach ($race['entries'] as $i => &$entry) {
            OuterSource::keys($entry, ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'bins']);
            $g = $growth[$i];
            $normalized = Signal::normalized($g, $scale);
            if ($g['entry_id'] !== $entry['id'] || $g['race_id'] !== $race['race_id'] || $g['year'] !== $race['year'] || $g['bike'] !== $entry['bike']) {
                throw new RuntimeException('Adjustment signal identity mismatch.');
            }
            if ($k !== 0 && $normalized !== null && $normalized !== 0.0) {
                $entry['anchor'] += ($k / 100) * $normalized;
            }
            if (! is_finite($entry['anchor'])) {
                throw new RuntimeException('Nonfinite adjusted anchor.');
            }
        }
        unset($entry);

        return $race;
    }

    public function predict(array $row, int $k, float $scale, Bt03e03FitResultDto $fit): array
    {
        return $this->predictor->predict($this->apply($row['race'], $row['growth'], $k, $scale), $fit);
    }

    public static function primary(array $prediction): array
    {
        return array_map(fn ($i) => $prediction['decision']['primary_position_'.$i.'_bike'], [1, 2, 3]);
    }
}
