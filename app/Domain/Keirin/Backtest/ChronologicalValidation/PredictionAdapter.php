<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel;

final class PredictionAdapter
{
    public function __construct(private readonly Predictor $predictor, private readonly EffectBinBuilder $bins, private readonly Bt03e05MetricEvaluator $baseline) {}

    public function predict(array $race, LoadedModel $model, Scope $scope): array
    {
        $raceKeys = array_keys($race);
        sort($raceKeys);
        BundleRules::require($raceKeys === ['entries', 'race_id', 'year'], 'Unapproved model race fields.');
        BundleRules::require(is_int($race['year']) && $race['year'] >= (int) substr($scope->from, 0, 4) && $race['year'] <= (int) substr($scope->to, 0, 4)
            && in_array($race['year'], $scope->years(), true) && is_int($race['race_id']) && $race['race_id'] > 0
            && is_array($race['entries']) && array_is_list($race['entries']) && count($race['entries']) >= 5 && count($race['entries']) <= 9, 'Model identity/year outside explicit scope.');
        $ids = $bikes = [];
        foreach ($race['entries'] as $e) {
            BundleRules::require(is_int($e['id']) && $e['id'] > 0 && is_int($e['bike']) && $e['bike'] >= 1 && $e['bike'] <= 9
                && ! isset($ids[$e['id']]) && ! isset($bikes[$e['bike']]), 'Duplicate/invalid model entry identity.');
            $ids[$e['id']] = $bikes[$e['bike']] = true;
        }
        $values = array_map(fn (array $e): mixed => $e['raw'], $race['entries']);
        foreach ($values as $v) {
            BundleRules::require((is_int($v) || is_float($v)) && is_finite($v) && $v > 0.0, 'Invalid STAT01 anchor raw.');
        }
        $mean = array_sum($values) / count($values);
        $sd = sqrt(array_sum(array_map(fn ($v): float => ($v - $mean) ** 2, $values)) / count($values));
        $ranked = $this->baseline->rankBaseline($race['race_id'], $race['entries']);
        foreach ($race['entries'] as &$e) {
            $keys = array_keys($e);
            sort($keys);
            $expected = ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'signals', 'history', 'history_status'];
            sort($expected);
            $anchor = $sd > 0 ? ($e['raw'] - $mean) / $sd : 0.0;
            $rank = 1 + count(array_filter($values, fn ($v): bool => $v > $e['raw']));
            BundleRules::require($keys === $expected && $e['stat01_rank'] === $rank && (float) $e['anchor'] === $anchor
                && $e['anchor_status'] === ($sd > 0 ? 'AVAILABLE' : 'ZERO_VARIANCE') && count($e['signals']) === 12 && count($e['history']) === 4, 'Model input/anchor contract mismatch.');
            foreach ($e['signals'] as $v) {
                BundleRules::require($v === null || ((is_int($v) || is_float($v)) && is_finite($v)), 'Invalid signal value.');
            }
            foreach ($e['history'] as $v) {
                BundleRules::require($v === null || (is_int($v) && $v >= 0), 'Invalid history count.');
            }
            BundleRules::require($e['history_status'] === 'AVAILABLE'
                ? ! in_array(null, $e['history'], true)
                : in_array($e['history_status'], ['MISSING_TARGET_METADATA', 'LEFT_TRUNCATED', 'INVALID_HISTORY', 'PARTIAL_HISTORY', 'MISSING_METHOD_HISTORY', 'NO_HISTORY'], true)
                    && $e['history'] === [null, null, null, null], 'History status/value mismatch.');
            $e['bins'] = $model->layout->assign([...$e['signals'], ...$e['history']], $this->bins);
            unset($e['signals'], $e['history'], $e['history_status']);
        }
        unset($e);
        $p = $this->predictor->predict($race, $model->fit);
        $p['decision']['prediction_origin'] = 'SAVED_MODEL_REUSE_NO_FIT';

        return ['c1' => $p, 'baseline' => array_slice(array_column($ranked, 'bike'), 0, 3), 'baseline_tie_audit' => $ranked];
    }
}
