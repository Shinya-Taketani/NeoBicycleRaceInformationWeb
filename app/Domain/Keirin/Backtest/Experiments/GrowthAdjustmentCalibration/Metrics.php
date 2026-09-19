<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Metrics
{
    public function __construct(private readonly Bt03e05MetricEvaluator $evaluator) {}

    public function contribution(array $context, array $prediction): array
    {
        return array_intersect_key($this->evaluator->raceComparison($context, $prediction['decision'])['candidate'], array_flip(Contract::METRICS));
    }

    public static function context(array $labels, array $race): array
    {
        if ($labels['year'] !== $race['year'] || $labels['race_id'] !== $race['race_id'] || count($labels['entries']) !== count($race['entries'])) {
            throw new RuntimeException('Outcome universe mismatch.');
        }
        foreach ($race['entries'] as $i => $entry) {
            Files::same(array_intersect_key($entry, array_flip(['id', 'bike', 'raw'])), array_intersect_key($labels['entries'][$i], array_flip(['id', 'bike', 'raw'])), 'label entry');
        }

        return ['year' => $labels['year'], 'race_id' => $labels['race_id'], 'entries' => array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'bike', 'raw', 'rank', 'status'])), $labels['entries'])];
    }

    public static function empty(): array
    {
        return array_fill_keys(Contract::METRICS, ['numerator' => 0.0, 'denominator' => 0.0]);
    }

    public static function add(array &$total, array $values): void
    {
        foreach (Contract::METRICS as $metric) {
            $total[$metric]['numerator'] += $values[$metric]['numerator'];
            $total[$metric]['denominator'] += $values[$metric]['denominator'];
        }
    }

    public static function finish(array $total, array $baseline): array
    {
        $out = [];
        foreach (Contract::METRICS as $metric) {
            $v = $total[$metric];
            if ($v['denominator'] !== $baseline[$metric]['denominator'] || $v['denominator'] < 0) {
                throw new RuntimeException('Metric denominator mismatch/empty.');
            }
            $rate = $v['denominator'] > 0 ? $v['numerator'] / $v['denominator'] : null;
            $base = $v['denominator'] > 0 ? $baseline[$metric]['numerator'] / $v['denominator'] : null;
            $out[$metric] = $v + ['rate' => $rate, 'baseline_numerator' => $baseline[$metric]['numerator'], 'baseline_rate' => $base, 'delta' => $rate === null ? null : $rate - $base];
        }

        return $out;
    }
}
