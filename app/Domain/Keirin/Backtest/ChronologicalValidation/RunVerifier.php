<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

final class RunVerifier
{
    public function verify(Store $store): array
    {
        $refs = $store->all();
        $headerRef = $store->reference('run');
        $header = $store->get($headerRef)['data'];
        $indexes = [];
        $results = $evaluations = [];
        foreach ($refs as $ref) {
            $b = $store->get($ref);
            if ($b['kind'] === 'validation_run' && ($b['data']['event'] ?? null) === 'RUN_INDEX') {
                $indexes[] = $b['data'];
            }
            if ($b['kind'] === 'result_bundle') {
                $results[$b['data']['day_lock']['bundle_id']][$b['data']['external_race_key']][] = $ref;
            }
            if ($b['kind'] === 'evaluation_bundle') {
                $evaluations[$b['data']['result']['bundle_id']] = $b['data']['result'];
            }
        }
        BundleRules::require($indexes !== [], 'Run incomplete: only retained stages, no final day index.');
        foreach ($indexes as $index) {
            BundleRules::require($index['header'] === $headerRef && Json::hash($index['execution_identity']) === Json::hash($header['execution_identity']), 'Run index/header mismatch.');
            $expected = [];
            foreach ($header['candidate_scope'] as $c) {
                $expected[$c['race_date']][] = $c['external_race_key'];
            }
            $days = array_keys($index['days']);
            $expectedDays = array_keys($expected);
            sort($days);
            sort($expectedDays);
            BundleRules::require($days === $expectedDays, 'Run day coverage mismatch.');
            foreach ($index['days'] as $day => $ref) {
                $lock = $store->get($ref)['data'];
                $keys = array_column($lock['candidates'], 'external_race_key');
                sort($keys);
                sort($expected[$day]);
                BundleRules::require($lock['state'] === 'DAY_PREDICTIONS_LOCKED' && $lock['run_id'] === $header['run_id'] && $lock['date'] === $day && $keys === $expected[$day], 'Run candidate coverage mismatch.');
                $count = $lock['counts'];
                BundleRules::require($count['C'] === $count['X'] + $count['T'] && count($lock['predictions']) === $count['T'], 'Candidate count mismatch.');
                $prefix = 'd'.str_replace('-', '', $day);
                $receipt = $store->get($store->reference($prefix.'-opened'))['data'];
                BundleRules::require($receipt['state'] === 'RESULTS_OPENED' && $receipt['day_lock'] === $ref, 'Run result-opening receipt missing or mismatched.');
                $epoch = $store->get($store->reference($prefix.'-next-epoch'))['data'];
                BundleRules::require($epoch['parent_epoch'] === $lock['history_epoch'] && $epoch['logical_available_from'] === Scope::day($day)->modify('+1 day')->format('Y-m-d'), 'Run next epoch missing or mismatched.');
                foreach ($lock['predictions'] as $key => $methods) {
                    $versions = $results[$ref['bundle_id']][$key] ?? [];
                    BundleRules::require($versions !== [], 'Run result coverage incomplete.');
                    foreach ($versions as $version) {
                        $result = $store->get($version)['data'];
                        BundleRules::require($result['predictions'] === $methods && ($evaluations[$version['bundle_id']] ?? null) === $version, 'Run evaluation coverage incomplete.');
                    }
                    BundleRules::require(count(array_filter($epoch['results'], fn (array $r): bool => in_array($r, $versions, true))) >= 1, 'Run result absent from next epoch.');
                }
                foreach (BundleRules::METHODS as $method) {
                    $p = 0;
                    foreach ($lock['predictions'] as $methods) {
                        $p += (int) ($store->get($methods[$method])['data']['state'] === 'PREDICTED');
                    }
                    BundleRules::require($count[$method] === ['T' => $count['T'], 'P' => $p, 'N' => $count['T'] - $p, 'U' => 0]
                        || Json::hash($count[$method]) === Json::hash(['T' => $count['T'], 'P' => $p, 'N' => $count['T'] - $p, 'U' => 0]), 'Terminal count mismatch.');
                }
            }
        }

        return ['status' => 'VERIFIED', 'bundles' => count($refs), 'days' => count($indexes[0]['days']), 'inference_count' => 0];
    }
}
