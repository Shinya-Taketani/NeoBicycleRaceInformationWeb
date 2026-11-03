<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;

final class PrimaryEvaluator
{
    public const METRICS = ['PRIMARY_EXACT_ORDERED_TOP3_RATE', 'PRIMARY_TUPLE_POSITION_1_ACCURACY', 'PRIMARY_TUPLE_POSITION_2_ACCURACY', 'PRIMARY_TUPLE_POSITION_3_ACCURACY', 'PRIMARY_EXACT_TOP3_SET_RATE'];

    public function evaluate(array $predictions, ?array $result): array
    {
        $official = [];
        $reason = null;
        $final = true;
        if ($result === null) {
            $reason = 'RESULT_MISSING';
            $final = false;
        } elseif (! $result['identity_audit']['verified']) {
            $reason = 'RESULT_IDENTITY_OR_SET_MISMATCH';
            $final = false;
        } elseif ($result['official_status'] === 'CANCELLED') {
            $reason = 'CANCELLED';
        } elseif (! in_array($result['official_status'], ['CONFIRMED', 'CORRECTED'], true)) {
            $reason = 'RESULT_NOT_CONFIRMED';
            $final = false;
        } else {
            $groups = [];
            foreach ($result['entries'] as $entry) {
                BundleRules::require(RaceEntryResultStatus::tryFrom($entry['status']) !== null, 'Unknown official result status.');
                if (in_array($entry['status'], ['FINISHED', 'TIED'], true)) {
                    BundleRules::require(is_int($entry['rank']) && $entry['rank'] > 0 && $entry['rank'] <= count($result['entries']), 'Invalid official rank.');
                    $groups[$entry['rank']][] = $entry;
                } else {
                    BundleRules::require($entry['rank'] === null, 'Abnormal result cannot have invented rank.');
                }
            }
            foreach ($groups as $entries) {
                foreach ($entries as $entry) {
                    BundleRules::require($entry['status'] === (count($entries) > 1 ? 'TIED' : 'FINISHED'), 'Official tie group mismatch.');
                }
            }
            foreach ([1, 2, 3] as $rank) {
                if (count($groups[$rank] ?? []) !== 1) {
                    $reason = count($groups[$rank] ?? []) > 1 ? 'TIED_OFFICIAL_TOP3' : 'MISSING_OFFICIAL_TOP3';
                    break;
                }
                $official[] = $groups[$rank][0]['bike'];
            }
        }
        $methods = $coverage = [];
        $eligible = [];
        foreach (BundleRules::METHODS as $method) {
            $p = $predictions[$method];
            $predicted = $p['state'] === 'PREDICTED';
            $eligible[$method] = $predicted && $reason === null;
            $methods[$method] = $this->metrics($p['primary'], $official, $eligible[$method]);
            $coverage[$method] = ['T' => 1, 'P' => (int) $predicted, 'N' => (int) ! $predicted, 'U' => 0,
                'E' => (int) $eligible[$method], 'coverage' => (int) $predicted, 'reasons' => $p['reasons']];
        }
        $pair = ! in_array(false, $eligible, true);
        $paired = [];
        foreach (BundleRules::METHODS as $method) {
            $paired[$method] = $this->metrics($predictions[$method]['primary'], $official, $pair);
        }

        return ['methods' => $methods, 'paired' => $paired, 'coverage' => $coverage, 'E_pair' => (int) $pair, 'excluded' => $reason === null ? [] : [$reason], 'is_final' => $final];
    }

    public function combine(iterable $contributions): array
    {
        $out = ['methods' => [], 'paired' => [], 'coverage' => [], 'E_pair' => 0, 'is_final' => true, 'excluded' => [], 'no_prediction_reasons' => []];
        foreach (BundleRules::METHODS as $method) {
            $out['methods'][$method] = $out['paired'][$method] = $this->metrics(null, [], false);
            $out['coverage'][$method] = ['T' => 0, 'P' => 0, 'N' => 0, 'U' => 0, 'E' => 0, 'coverage' => null];
            $out['no_prediction_reasons'][$method] = [];
        }
        foreach ($contributions as $c) {
            $out['E_pair'] += $c['E_pair'];
            $out['is_final'] = $out['is_final'] && $c['is_final'];
            foreach ($c['excluded'] as $reason) {
                $out['excluded'][$reason] = ($out['excluded'][$reason] ?? 0) + 1;
            }
            foreach (BundleRules::METHODS as $method) {
                foreach (array_unique(array_column($c['coverage'][$method]['reasons'] ?? [], 'code')) as $reason) {
                    $out['no_prediction_reasons'][$method][$reason] = ($out['no_prediction_reasons'][$method][$reason] ?? 0) + 1;
                }
                foreach (['methods', 'paired'] as $kind) {
                    foreach (self::METRICS as $metric) {
                        $sum = $out[$kind][$method][$metric] ?? ['numerator' => 0, 'denominator' => 0, 'rate' => null];
                        $sum['numerator'] += $c[$kind][$method][$metric]['numerator'];
                        $sum['denominator'] += $c[$kind][$method][$metric]['denominator'];
                        $sum['rate'] = $sum['denominator'] > 0 ? $sum['numerator'] / $sum['denominator'] : null;
                        $sum['reason'] = $sum['denominator'] > 0 ? null : 'UNEVALUABLE_ZERO_DENOMINATOR';
                        $out[$kind][$method][$metric] = $sum;
                    }
                }
                foreach (['T', 'P', 'N', 'U', 'E'] as $key) {
                    $out['coverage'][$method][$key] = ($out['coverage'][$method][$key] ?? 0) + $c['coverage'][$method][$key];
                }
                $counts = &$out['coverage'][$method];
                $counts['coverage'] = $counts['T'] > 0 ? $counts['P'] / $counts['T'] : null;
                unset($counts);
            }
        }

        return $out;
    }

    private function metrics(?array $primary, array $official, bool $eligible): array
    {
        $hits = $eligible ? [(int) ($primary === $official), (int) ($primary[0] === $official[0]), (int) ($primary[1] === $official[1]), (int) ($primary[2] === $official[2]),
            (int) (count(array_intersect($primary, $official)) === 3)] : [0, 0, 0, 0, 0];
        $out = [];
        foreach (self::METRICS as $i => $metric) {
            $out[$metric] = ['numerator' => $hits[$i], 'denominator' => (int) $eligible, 'rate' => $eligible ? $hits[$i] : null, 'reason' => $eligible ? null : 'UNEVALUABLE_ZERO_DENOMINATOR'];
        }

        return $out;
    }
}
