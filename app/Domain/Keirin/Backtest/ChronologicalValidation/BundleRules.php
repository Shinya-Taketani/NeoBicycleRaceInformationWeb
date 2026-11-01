<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use RuntimeException;

final class BundleRules
{
    public const METHODS = ['SAVED_FINAL_C1', 'STAT01_RAW_DESC_FIXED_TUPLE'];

    public function validate(string $kind, array $data, Store $store): void
    {
        $contract = Json::read(dirname(__DIR__, 5).'/docs/contracts/stat-2026-chronological-validation-v1.json');
        foreach (array_keys([...$contract['schemas'][$kind]['fields'], ...($contract['offline_implementation_profile']['additional_required_fields'][$kind] ?? [])]) as $field) {
            self::require(array_key_exists($field, $data), 'Required bundle field missing: '.$field);
        }
        if ($kind === 'prediction_bundle') {
            $this->prediction($data, $store);
        } elseif ($kind === 'day_manifest' && $data['state'] !== 'CANDIDATES_LOCKED') {
            $candidate = $store->get($data['candidate_manifest']);
            self::require($candidate['kind'] === 'day_manifest' && $candidate['data']['state'] === 'CANDIDATES_LOCKED', 'Earlier candidates required.');
            foreach (['run_id', 'date', 'target_set_sha256', 'history_epoch', 'candidates'] as $field) {
                self::require(Json::encode($data[$field]) === Json::encode($candidate['data'][$field]), 'Candidate/epoch drift.');
            }
            self::require($data['locked_at'] !== null && $data['technical_unprocessed'] === [], 'Incomplete day lock.');
            Scope::instant($data['locked_at']);
            $targetKeys = array_column(array_filter($data['candidates'], fn (array $c): bool => $c['eligibility'] === 'TARGET'), 'external_race_key');
            $keys = array_keys($data['predictions']);
            sort($targetKeys);
            sort($keys);
            self::require($keys === $targetKeys, 'Exact target prediction set required.');
            foreach ($data['predictions'] as $key => $methods) {
                self::require(self::sameKeys($methods, self::METHODS), 'Both methods required.');
                foreach ($methods as $method => $ref) {
                    self::require(is_array($ref), 'Non-null terminal reference required.');
                    $p = $store->get($ref);
                    self::require($p['kind'] === 'prediction_bundle' && $p['data']['external_race_key'] === $key
                        && $p['data']['method'] === $method && $p['data']['run_id'] === $data['run_id']
                        && $p['data']['candidate_ref']['manifest'] === $data['candidate_manifest'], 'Wrong race/method/candidate reference.');
                    $reasons = $data['no_prediction'][$key][$method] ?? [];
                    self::require(Json::encode($reasons) === Json::encode($p['data']['state'] === 'NO_PREDICTION' ? $p['data']['reasons'] : []), 'Inability reason index mismatch.');
                }
            }
            if ($data['state'] === 'RESULTS_OPENED') {
                self::require($data['day_lock'] !== null && $data['opened_at'] !== null, 'Result opening receipt missing.');
                $lock = $store->get($data['day_lock']);
                self::require($lock['data']['state'] === 'DAY_PREDICTIONS_LOCKED'
                    && Json::encode($lock['data']['predictions']) === Json::encode($data['predictions'])
                    && Scope::instant($data['opened_at']) >= Scope::instant($data['locked_at']), 'Invalid result opening receipt.');
            } else {
                self::require($data['state'] === 'DAY_PREDICTIONS_LOCKED' && $data['result_open_receipt'] === null, 'Unknown day state.');
            }
        } elseif ($kind === 'day_manifest') {
            self::require($data['candidate_manifest'] === null && $data['locked_at'] === null
                && $data['result_open_receipt'] === null && $data['no_prediction'] === [], 'Candidate manifest cannot reference future lock.');
            foreach ($data['predictions'] as $methods) {
                self::require($methods === array_fill_keys(self::METHODS, null), 'Candidate prediction refs must be unprocessed.');
            }
        } elseif ($kind === 'result_bundle') {
            $lock = $store->get($data['day_lock']);
            $receipt = $store->get($data['opening_receipt']);
            self::require($lock['data']['state'] === 'DAY_PREDICTIONS_LOCKED'
                && $receipt['data']['state'] === 'RESULTS_OPENED' && $receipt['data']['day_lock'] === $data['day_lock']
                && Json::encode($data['predictions']) === Json::encode($lock['data']['predictions'][$data['external_race_key']] ?? null), 'Result terminal refs/receipt mismatch.');
            self::require(($data['result_version'] === 1 && $data['parent_result'] === null)
                || ($data['result_version'] > 1 && $data['parent_result'] !== null && $data['official_status'] === 'CORRECTED'), 'Result correction needs a parent version.');
            if ($data['parent_result'] !== null) {
                $parent = $store->get($data['parent_result'])['data'];
                self::require($parent['external_race_key'] === $data['external_race_key'] && $parent['predictions'] === $data['predictions']
                    && $data['result_version'] === $parent['result_version'] + 1, 'Invalid correction parent.');
                self::require(RaceResultStatus::Corrected->canTransitionFrom(RaceResultStatus::from($parent['official_status'])), 'Invalid official correction transition.');
            }
            $fixed = $store->get($lock['data']['candidate_manifest'])['data'];
            $candidates = array_values(array_filter($fixed['candidates'], fn (array $c): bool => $c['external_race_key'] === $data['external_race_key']));
            self::require(count($candidates) === 1 && Json::hash((new ResultMatcher)->match($candidates[0], $data)) === Json::hash($data['identity_audit']), 'Saved result identity audit mismatch.');
            (new PrimaryEvaluator)->evaluate(array_fill_keys(self::METHODS, ['state' => 'NO_PREDICTION', 'primary' => null, 'reasons' => []]), $data);
        } elseif ($kind === 'evaluation_bundle') {
            $result = $store->get($data['result']);
            self::require($result['kind'] === 'result_bundle', 'Evaluation requires a saved result.');
            $predictions = [];
            foreach ($result['data']['predictions'] as $method => $ref) {
                $predictions[$method] = $store->get($ref)['data'];
            }
            $computed = (new PrimaryEvaluator)->evaluate($predictions, $result['data']);
            self::require($data['result_version'] === $result['data']['result_version']
                && $data['prediction_hash'] === $result['data']['predictions'][self::METHODS[0]]['manifest_sha256']
                && $data['comparison_prediction'] === $result['data']['predictions'][self::METHODS[1]]
                && $data['comparison_prediction_hash'] === $data['comparison_prediction']['manifest_sha256'], 'Evaluation prediction/result binding mismatch.');
            foreach (['methods' => 'metrics', 'coverage' => 'coverage', 'excluded' => 'excluded', 'is_final' => 'is_final'] as $source => $field) {
                self::require(Json::hash($computed[$source]) === Json::hash($data[$field]), 'Saved evaluation metrics mismatch.');
            }
            self::require(Json::hash($computed) === Json::hash($data['contribution'])
                && Json::hash($data['contributions']) === Json::hash([$result['data']['external_race_key'] => $computed])
                && $data['paired_set_sha256'] === Json::hash([$result['data']['external_race_key'], $computed['E_pair']]), 'Saved evaluation contribution mismatch.');
        } elseif ($kind === 'input_bundle') {
            self::require($data['input_as_of'] !== null && $data['capture_refs'] !== [], 'Normal input requires time and capture evidence.');
            Scope::instant($data['input_as_of']);
        } elseif ($kind === 'history_epoch') {
            Scope::day($data['logical_available_from']);
        } elseif ($kind === 'validation_run') {
            self::require(in_array($data['scope']['kind'], ['SYNTHETIC_TEST', 'LEGACY_COMPATIBILITY'], true)
                && $data['real_execution_authorized'] === false, 'Real execution forbidden.');
        }
    }

    private function prediction(array $p, Store $store): void
    {
        self::require(in_array($p['state'], ['PREDICTED', 'NO_PREDICTION'], true)
            && in_array($p['method'], self::METHODS, true), 'Unknown prediction state/method.');
        $candidate = $store->get($p['candidate_ref']['manifest']);
        self::require($candidate['kind'] === 'day_manifest' && $candidate['data']['state'] === 'CANDIDATES_LOCKED'
            && $candidate['data']['run_id'] === $p['run_id'] && $candidate['data']['history_epoch'] === $p['history_epoch']
            && $p['candidate_ref']['external_race_key'] === $p['external_race_key'], 'Prediction candidate binding invalid.');
        $found = array_values(array_filter($candidate['data']['candidates'], fn (array $c): bool => $c['external_race_key'] === $p['external_race_key'] && $c['eligibility'] === 'TARGET'));
        self::require(count($found) === 1, 'Prediction outside target set.');
        self::require(is_array($p['capture_refs']) && array_is_list($p['capture_refs']), 'Capture array required.');
        self::require($p['capture_refs'] === [] ? $p['capture_unavailable_reasons'] !== [] : $p['capture_unavailable_reasons'] === [], 'Capture absence needs evidence reasons.');
        if ($p['input'] === null) {
            self::require($p['state'] === 'NO_PREDICTION' && $p['input_availability'] === 'NOT_CONSTRUCTIBLE_KNOWN_MISSING'
                && $p['input_unavailable_reasons'] !== [], 'Null input is not a normal prediction.');
            $inputId = 'd'.str_replace('-', '', $candidate['data']['date']).'-'.substr(hash('sha256', $p['external_race_key']), 0, 12).'-input';
            self::require(! $store->has($inputId), 'Existing normal input reference must be retained.');
            foreach ($p['input_unavailable_reasons'] as $reason) {
                self::require(in_array($reason['code'] ?? null, ['CATEGORY_UNDETERMINED', 'MISSING_SCHEDULE', 'MISSING_MEETING_CONTEXT', 'UNRESOLVED_IDENTITY', 'MISSING_RACE_SCORE', 'INVALID_STAT01_ANCHOR', 'KNOWN_INPUT_SCHEMA_UNAVAILABLE'], true)
                    && in_array($reason, $p['reasons'], true), 'Known missing-input reason required.');
            }
        } else {
            $input = $store->get($p['input']);
            self::require($input['kind'] === 'input_bundle' && Json::hash($input['data']['candidate_ref']) === Json::hash($p['candidate_ref'])
                && $input['data']['history_epoch'] === $p['history_epoch'] && $p['input_availability'] === 'AVAILABLE'
                && $p['input_unavailable_reasons'] === [] && Json::encode($input['data']['capture_refs']) === Json::encode($p['capture_refs']), 'Input binding mismatch.');
        }
        foreach ([...$p['capture_unavailable_reasons'], ...$p['input_unavailable_reasons']] as $reason) {
            self::require(in_array($reason, $p['reasons'], true), 'Missing evidence/input reason must appear in canonical reasons.');
        }
        if ($p['state'] === 'NO_PREDICTION') {
            self::require($p['reasons'] !== [], 'Inability reasons required.');
            foreach (['primary', 'probabilities', 'utilities', 'decision', 'tie_audit'] as $field) {
                self::require($p[$field] === null, 'Inability cannot contain predictions.');
            }
        } else {
            self::require($p['input'] !== null && is_array($p['primary']) && count($p['primary']) === 3
                && count(array_unique($p['primary'])) === 3, 'Three distinct Primary bikes required.');
            $input = $store->get($p['input'])['data'];
            self::require(array_diff($p['primary'], array_column($input['entries'], 'bike')) === [], 'Primary outside roster.');
            self::require($p['method'] === self::METHODS[0] ? $p['model_sha256'] !== null : $p['baseline_rule_version'] !== null, 'Model/baseline identity required.');
            if ($p['method'] === self::METHODS[0]) {
                self::require(is_array($p['probabilities']) && is_array($p['decision']) && $p['capture_refs'] !== [], 'C1 probability/decision/capture missing.');
                $sums = [0.0, 0.0, 0.0];
                self::require(array_column($p['probabilities']['entries'], 'id') === array_column($input['entries'], 'original_entry_id')
                    && array_column($p['probabilities']['entries'], 'bike') === array_column($input['entries'], 'bike'), 'C1 probability roster mismatch.');
                foreach ($p['probabilities']['entries'] as $entry) {
                    $total = 0.0;
                    foreach ([1, 2, 3] as $n) {
                        $v = $entry['position_'.$n.'_probability'];
                        self::require((is_float($v) || is_int($v)) && is_finite($v) && $v >= 0.0 && $v <= 1.0, 'Invalid saved probability.');
                        $sums[$n - 1] += $v;
                        $total += $v;
                        self::require($p['primary'][$n - 1] === $p['decision']['primary_position_'.$n.'_bike'], 'Saved Primary/decision mismatch.');
                    }
                    self::require(abs($total - $entry['top3_probability']) <= 1e-12 && $total <= 1.0 + 1e-12, 'Saved top3 probability mismatch.');
                }
                foreach ($sums as $sum) {
                    self::require(abs($sum - 1.0) <= 1e-12, 'Saved position probability sum mismatch.');
                }
                self::require(abs($p['probabilities']['probability_invariants']['ordered_joint_sum'] - 1.0) <= 1e-12, 'Saved joint probability sum mismatch.');
            }
        }
    }

    public static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    public static function reasons(array $codes): array
    {
        return array_map(fn (string $code): array => ['code' => $code, 'source_ref' => null, 'detail' => $code], $codes);
    }

    private static function sameKeys(array $data, array $expected): bool
    {
        $actual = array_keys($data);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
