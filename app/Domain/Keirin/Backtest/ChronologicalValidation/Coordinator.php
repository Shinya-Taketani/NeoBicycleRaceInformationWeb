<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class Coordinator
{
    public function __construct(private readonly RacecardSource $racecards, private readonly StatEngine $stats, private readonly PredictionAdapter $predictor,
        private readonly PrimaryEvaluator $evaluator, private readonly ResultMatcher $matcher, private readonly ?Closure $clock = null, private readonly ?Closure $checkpoint = null) {}

    public function execute(array $manifest, LoadedModel $model, string $modelHash, Store $store, ResultSource $results, ?Closure $integrity = null): array
    {
        $scope = new Scope(...$manifest['scope']);
        BundleRules::require($manifest['material_kind'] === $scope->kind, 'Input manifest material mismatch.');
        if ($scope->kind === 'SYNTHETIC_TEST') {
            $root = $manifest['fixture_root'] ?? '';
            BundleRules::require(getenv('APP_ENV') === 'testing' && is_string($root) && realpath($root) === $root
                && str_starts_with($root, '/tmp/composition-result-temporary-'), 'Synthetic input requires a recorded dedicated testing root.');
            BundleRules::require(Json::read($root.'/CREATED.json')['source'] === $root, 'Synthetic creation record mismatch.');
            foreach ($manifest['history']['sources'] as $source) {
                BundleRules::require(str_starts_with($source['path'], $root.'/'), 'Synthetic history outside recorded root.');
            }
            foreach ($manifest['candidates'] as $candidate) {
                if ($candidate['capture'] !== null) {
                    BundleRules::require(str_starts_with($candidate['capture']['private_ref'], $root.'/'), 'Synthetic capture outside recorded root.');
                }
            }
        }

        return $store->locked('run', function () use ($manifest, $model, $modelHash, $store, $results, $scope, $integrity): array {
            try {
                return $this->run($manifest, $model, $modelHash, $store, $results, $scope, $integrity);
            } catch (Throwable $error) {
                $stage = $store->root.'/stages/failure-'.bin2hex(random_bytes(12));
                if (mkdir($stage, 0700)) {
                    Json::write($stage.'/FAILED.json', Json::encode(['status' => 'FAILED_UNPROCESSED', 'exception' => $error::class, 'message' => $error->getMessage(), 'at' => $this->now()]));
                }
                throw $error;
            }
        });
    }

    private function run(array $manifest, LoadedModel $model, string $modelHash, Store $store, ResultSource $results, Scope $scope, ?Closure $integrity): array
    {
        $contractHash = hash_file('sha256', dirname(__DIR__, 5).'/docs/contracts/stat-2026-chronological-validation-v1.json');
        $identity = ['scope' => $manifest['scope'], 'run_id' => $manifest['run_id'], 'input_manifest_sha256' => Json::hash($manifest), 'model_sha256' => $modelHash, 'code_sha256' => self::codeHash(), 'contract_sha256' => $contractHash];
        foreach ($manifest['history']['sources'] as $source) {
            BundleRules::require($source['material_kind'] === $scope->kind, 'Initial epoch material scope mismatch.');
            Json::verify($source['path'], $source['seal']);
        }
        if ($store->has('run')) {
            $run = $store->get($store->reference('run'))['data'];
            BundleRules::require($run['execution_identity'] === $identity || Json::encode($run['execution_identity']) === Json::encode($identity), 'CONFLICT: run identity changed; re-prediction forbidden.');
            $epochRef = $run['initial_history'];
        } else {
            $epoch = ['epoch_id' => 'initial-history', 'parent_epoch' => null, 'logical_available_from' => $scope->from, 'history_event_from' => $manifest['history']['history_event_from'],
                'history_event_end_exclusive' => $scope->from.'T00:00:00+09:00', 'sources' => $manifest['history']['sources'], 'results' => [], 'inputs' => [], 'release_order' => [],
                'actual_capture_order' => [], 'publication_times' => [], 'publication_status' => 'UNKNOWN', 'exclusions' => [], 'created_at' => $this->now()];
            $epochRef = $store->put('initial-history', 'history_epoch', $epoch);
            $run = ['run_id' => $manifest['run_id'], 'scope' => $manifest['scope'], 'contract_version' => 'STAT-2026-CHRONOLOGICAL-VALIDATION-v1',
                'contract_sha256' => hash_file('sha256', dirname(__DIR__, 5).'/docs/contracts/stat-2026-chronological-validation-v1.json'), 'period' => ['from' => $scope->from, 'to' => $scope->to],
                'model' => ['sha256' => $modelHash, 'lambda' => $model->fit->lambda], 'code_sha256' => self::codeHash(), 'baseline_rule_version' => 'BT03E02-TIE-v1', 'initial_history' => $epochRef,
                'mode' => 'OFFLINE_CONNECTION', 'event' => 'HEADER', 'approval_receipt' => null, 'purpose' => $scope->kind, 'real_execution_authorized' => false, 'execution_identity' => $identity, 'created_at' => $this->now(), 'days' => []];
            $run += ['historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'formal_adoption' => false, 'points' => null, 'confidence' => null];
            $run['candidate_scope'] = array_map(fn (array $c): array => ['external_race_key' => $c['external_race_key'], 'race_date' => $c['race_date']], $manifest['candidates']);
            $store->put('run', 'validation_run', $run);
        }
        $days = [];
        $seen = [];
        foreach ($manifest['candidates'] as $candidate) {
            $scope->target($candidate['race_date']);
            BundleRules::require(! isset($seen[$candidate['external_race_key']]), 'Duplicate fixed race key.');
            $seen[$candidate['external_race_key']] = true;
            $days[$candidate['race_date']][] = $candidate;
        }
        ksort($days);
        $dayRefs = $contributions = [];
        $candidateCounts = ['C' => 0, 'X' => 0, 'T' => 0];
        foreach ($days as $day => $candidates) {
            BundleRules::require(count($candidates) <= 256, 'Bounded daily candidate capacity exceeded.');
            $prefix = 'd'.str_replace('-', '', $day);
            $candidateId = $prefix.'-candidates';
            if (! $store->has($candidateId) && $store->has($prefix.'-corrected-epoch')) {
                $corrected = $store->get($store->reference($prefix.'-corrected-epoch'))['data'];
                BundleRules::require($corrected['parent_epoch'] === $epochRef && $corrected['logical_available_from'] === $day, 'Correction epoch is not the unprocessed forward version.');
                $epochRef = $store->reference($prefix.'-corrected-epoch');
            }
            if ($store->has($candidateId)) {
                $fixed = $store->get($store->reference($candidateId))['data'];
                BundleRules::require($fixed['history_epoch'] === $epochRef, 'Restart epoch drift.');
            } else {
                $projected = $fixedCandidates = $unprocessed = $technical = [];
                foreach ($candidates as $candidate) {
                    try {
                        $p = $this->racecards->project($candidate, $scope);
                    } catch (Throwable $error) {
                        $technical[$candidate['external_race_key']] = BundleRules::reasons(['INPUT_TECHNICAL_FAILURE']);
                        $p = ['exclusion' => null, 'reasons' => ['TECHNICAL_UNPROCESSED'], 'race' => null, 'failure' => ['exception' => $error::class, 'message' => $error->getMessage()]];
                    }
                    $candidate['eligibility'] = $p['exclusion'] === null ? 'TARGET' : 'EXCLUDED';
                    $candidate['reason_codes'] = $p['exclusion'] === null ? $p['reasons'] : [$p['exclusion']];
                    $projected[$candidate['external_race_key']] = $p;
                    $fixedCandidates[] = $candidate;
                    if ($candidate['eligibility'] === 'TARGET') {
                        $unprocessed[$candidate['external_race_key']] = array_fill_keys(BundleRules::METHODS, null);
                    }
                }
                usort($fixedCandidates, fn (array $a, array $b): int => [$projected[$a['external_race_key']]['race']['scheduled_start_at'] ?? 'z', $a['track_code'], $a['race_number'], $a['external_race_key']]
                    <=> [$projected[$b['external_race_key']]['race']['scheduled_start_at'] ?? 'z', $b['track_code'], $b['race_number'], $b['external_race_key']]);
                $fixed = ['run_id' => $manifest['run_id'], 'date' => $day, 'history_epoch' => $epochRef, 'candidates' => $fixedCandidates, 'target_set_sha256' => Json::hash($fixedCandidates),
                    'candidate_manifest' => null, 'predictions' => $unprocessed, 'no_prediction' => [], 'technical_unprocessed' => $technical, 'counts' => ['C' => count($fixedCandidates), 'X' => count($fixedCandidates) - count($unprocessed), 'T' => count($unprocessed), 'U' => count($unprocessed)],
                    'state' => 'CANDIDATES_LOCKED', 'locked_at' => null, 'result_open_receipt' => null, 'day_lock' => null, 'opened_at' => null, 'projections' => $projected];
                $store->put($candidateId, 'day_manifest', $fixed);
            }
            BundleRules::require($fixed['technical_unprocessed'] === [], 'Retained technical unprocessed candidates block day opening.');
            $candidateRef = $store->reference($candidateId);
            $lockId = $prefix.'-predictions';
            if (! $store->has($lockId)) {
                $lock = $fixed;
                $lock['candidate_manifest'] = $candidateRef;
                $epoch = $store->get($epochRef)['data'];
                foreach ($fixed['candidates'] as $candidate) {
                    if ($candidate['eligibility'] === 'EXCLUDED') {
                        continue;
                    }
                    $key = $candidate['external_race_key'];
                    $p = $fixed['projections'][$key];
                    $racePrefix = $prefix.'-'.substr(hash('sha256', $key), 0, 12);
                    $already = true;
                    foreach (BundleRules::METHODS as $i => $method) {
                        $already = $already && $store->has($racePrefix.'-p'.$i);
                    }
                    if (! $already) {
                        $inputRef = null;
                        $predicted = null;
                        $reasons = $p['reasons'];
                        if ($reasons === []) {
                            $computed = $this->stats->calculate($p, $epoch, $store, $scope, $candidateRef, $this->now());
                            $input = ['input_id' => $racePrefix.'-input', 'run_id' => $manifest['run_id'], 'external_race_key' => $key, 'candidate_ref' => ['manifest' => $candidateRef, 'external_race_key' => $key],
                                'original_race_id' => $candidate['original_race_id'], 'race' => $p['race'], 'entries' => $p['entries'], 'input_as_of' => $p['input_as_of'], 'cutoff_source' => $p['cutoff_source'],
                                'capture_refs' => $p['capture_refs'], 'actual_fetched_at' => array_column($p['capture_refs'], 'fetched_at'), 'actual_saved_at' => array_column($p['capture_refs'], 'saved_at'),
                                'history_epoch' => $epochRef, 'stat_sources' => $computed['stat_sources'], 'c1_history' => $computed['c1_history'], 'model_input' => $computed['model_input'],
                                'history_used' => $computed['history_used'], 'history_excluded' => $computed['history_excluded'], 'quality' => [], 'sealed_at' => $this->now()];
                            foreach ($computed['stat_sources'] as $audit) {
                                if ($audit['STAT-01']['status'] !== 'VALID' || $audit['STAT-01']['quality_status'] !== 'FULL') {
                                    $input['quality'] = BundleRules::reasons(['INVALID_STAT01_ANCHOR']);
                                    break;
                                }
                            }
                            if ($store->has($racePrefix.'-input')) {
                                $input = $store->get($store->reference($racePrefix.'-input'))['data'];
                            }
                            $inputRef = $store->put($racePrefix.'-input', 'input_bundle', $input);
                            $valid = true;
                            foreach ($input['stat_sources'] as $audits) {
                                $a = $audits['STAT-01'];
                                $raw = $a['features']['RACE_SCORE_RAW'];
                                $valid = $valid && $a['status'] === 'VALID' && $a['quality_status'] === 'FULL' && $raw !== null && is_finite($raw) && $raw > 0;
                            }
                            if ($valid) {
                                $predicted = $this->predictor->predict($input['model_input'], $model, $scope);
                            } else {
                                $reasons = ['INVALID_STAT01_ANCHOR'];
                            }
                        }
                        foreach (BundleRules::METHODS as $i => $method) {
                            if ($store->has($racePrefix.'-p'.$i)) {
                                continue;
                            }
                            $row = ['prediction_id' => $racePrefix.'-p'.$i, 'run_id' => $manifest['run_id'], 'external_race_key' => $key, 'method' => $method,
                                'candidate_ref' => ['manifest' => $candidateRef, 'external_race_key' => $key], 'input' => $inputRef,
                                'input_availability' => $inputRef === null ? 'NOT_CONSTRUCTIBLE_KNOWN_MISSING' : 'AVAILABLE', 'input_unavailable_reasons' => $inputRef === null ? BundleRules::reasons($reasons) : [],
                                'capture_refs' => $p['capture_refs'], 'capture_unavailable_reasons' => $p['capture_refs'] === [] ? BundleRules::reasons(['CAPTURE_EVIDENCE_UNAVAILABLE']) : [],
                                'model_sha256' => $i === 0 ? $modelHash : null, 'baseline_rule_version' => $i === 1 ? 'BT03E02-TIE-v1' : null, 'history_epoch' => $epochRef,
                                'state' => $predicted === null ? 'NO_PREDICTION' : 'PREDICTED', 'primary' => null, 'probabilities' => null, 'utilities' => null, 'decision' => null, 'tie_audit' => null,
                                'reasons' => BundleRules::reasons(array_values(array_unique([...$reasons, ...($p['capture_refs'] === [] ? ['CAPTURE_EVIDENCE_UNAVAILABLE'] : [])]))), 'saved_at' => $this->now()];
                            if ($predicted !== null) {
                                $row['primary'] = $i === 0 ? array_map(fn (int $n): int => $predicted['c1']['decision']['primary_position_'.$n.'_bike'], [1, 2, 3]) : $predicted['baseline'];
                                $row['probabilities'] = $i === 0 ? $predicted['c1']['probabilities'] : null;
                                $row['decision'] = $i === 0 ? $predicted['c1']['decision'] : null;
                                $row['utilities'] = $i === 0 ? array_column($predicted['c1']['probabilities']['entries'], 'utilities', 'id') : null;
                                $row['tie_audit'] = $i === 0 ? $predicted['c1']['decision'] : $predicted['baseline_tie_audit'];
                            }
                            $store->put($racePrefix.'-p'.$i, 'prediction_bundle', $row);
                        }
                    }
                    foreach (BundleRules::METHODS as $i => $method) {
                        $ref = $store->reference($racePrefix.'-p'.$i);
                        $row = $store->get($ref)['data'];
                        $lock['predictions'][$key][$method] = $ref;
                        $lock['no_prediction'][$key][$method] = $row['state'] === 'NO_PREDICTION' ? $row['reasons'] : [];
                    }
                }
                $this->pause('BEFORE_LOCK');
                foreach ($fixed['projections'] as $projection) {
                    foreach ($projection['capture_refs'] as $capture) {
                        Json::verify($capture['private_ref'], $capture['raw']);
                    }
                }
                foreach ($epoch['sources'] as $source) {
                    Json::verify($source['path'], $source['seal']);
                }
                if ($integrity !== null) {
                    $integrity();
                }
                BundleRules::require(self::codeHash() === $identity['code_sha256'], 'Execution code changed before lock.');
                $lock['state'] = 'DAY_PREDICTIONS_LOCKED';
                $lock['locked_at'] = $this->now();
                $lock['counts']['U'] = 0;
                foreach (BundleRules::METHODS as $method) {
                    $pCount = 0;
                    foreach ($lock['predictions'] as $methods) {
                        $pCount += (int) ($store->get($methods[$method])['data']['state'] === 'PREDICTED');
                    }
                    $lock['counts'][$method] = ['T' => $lock['counts']['T'], 'P' => $pCount, 'N' => $lock['counts']['T'] - $pCount, 'U' => 0];
                }
                $store->put($lockId, 'day_manifest', $lock);
            }
            $lockRef = $store->reference($lockId);
            $lock = $store->get($lockRef)['data'];
            $receiptId = $prefix.'-opened';
            if (! $store->has($receiptId)) {
                $receipt = [...$lock, 'state' => 'RESULTS_OPENED', 'day_lock' => $lockRef, 'opened_at' => $this->now()];
                $store->put($receiptId, 'day_manifest', $receipt);
            }
            $receiptRef = $store->reference($receiptId);
            $store->get($receiptRef);
            $this->pause('AFTER_RECEIPT');
            $pending = [];
            foreach ($fixed['candidates'] as $candidate) {
                if ($candidate['eligibility'] === 'TARGET') {
                    $pending[$candidate['external_race_key']] = $candidate;
                }
            }
            // Only this line opens the day's result source, after all terminal refs and receipt.
            $dayResults = $pending === [] ? [] : $results->read($day, $receiptRef);
            foreach ($dayResults as $result) {
                $key = $result['external_race_key'];
                BundleRules::require(isset($pending[$key]), 'Unexpected/duplicate result race.');
                $candidate = $pending[$key];
                unset($pending[$key]);
                $id = $prefix.'-'.substr(hash('sha256', $key), 0, 12).'-result-v'.$result['result_version'];
                if (! $store->has($id)) {
                    $audit = $this->matcher->match($candidate, $result);
                    $parent = $result['result_version'] === 1 ? null : $store->reference($prefix.'-'.substr(hash('sha256', $key), 0, 12).'-result-v'.($result['result_version'] - 1));
                    $row = [...$result, 'result_id' => $id, 'parent_result' => $parent, 'raw' => $result['source_seal'], 'fetch_log_or_capture_id' => $result['source_id'],
                        'official_publication_at' => null, 'publication_reason' => 'UNKNOWN', 'predictions' => $lock['predictions'][$key], 'day_lock' => $lockRef, 'opening_receipt' => $receiptRef, 'identity_audit' => $audit,
                        'parser_version' => 'CHRONOLOGICAL-OFFLINE-RESULT-v1', 'history_event' => null];
                    $projection = $fixed['projections'][$key];
                    if ($audit['verified'] && $projection['race'] !== null && $projection['race']['scheduled_start_at'] !== null && $projection['race']['meeting_start'] !== null && count($result['entries']) === count($projection['entries'])) {
                        $eventEntries = [];
                        foreach ($projection['entries'] as $e) {
                            $actual = array_values(array_filter($result['entries'], fn (array $a): bool => $a['bike'] === $e['bike']))[0];
                            $eventEntries[] = [...$e, 'rank' => $actual['rank'], 'status' => $actual['status'], 'winning_technique' => $actual['winning_technique']];
                        }
                        $row['history_event'] = ['material_kind' => $scope->kind, 'race' => $projection['race'], 'context' => $projection['context'], 'entries' => $eventEntries,
                            'race_status' => $result['official_status'], 'source_id' => $result['source_id'], 'verified' => true,
                            'logical_available_from' => Scope::day($day)->modify('+1 day')->format('Y-m-d'), 'input_fetched_at' => $projection['capture_refs'][0]['fetched_at'],
                            'fetched_at' => $result['fetched_at'], 'saved_at' => $result['saved_at'], 'confirmed_at' => null];
                    }
                    $store->put($id, 'result_bundle', $row);
                }
                $ref = $store->reference($id);
                $r = $store->get($ref)['data'];
                $this->pause('BEFORE_EVALUATION');
                $evaluationRef = $this->evaluation($store, $r, $ref, $id.'-evaluation');
                $contributions[] = $store->get($evaluationRef)['data']['contribution'];
            }
            BundleRules::require($pending === [], 'Unprocessed result races remain; day not final.');
            $nextId = $prefix.'-next-epoch';
            if ($store->has($nextId)) {
                $epochRef = $store->reference($nextId);
            } else {
                $previous = $store->get($epochRef)['data'];
                $newResults = $newInputs = [];
                foreach ($store->all() as $ref) {
                    $bundle = $store->get($ref);
                    if ($bundle['kind'] === 'result_bundle' && $bundle['data']['day_lock'] === $lockRef) {
                        $newResults[] = $ref;
                    }
                    if ($bundle['kind'] === 'input_bundle' && $bundle['data']['candidate_ref']['manifest'] === $candidateRef) {
                        $newInputs[] = $ref;
                    }
                }
                $epochRef = $store->put($nextId, 'history_epoch', [...$previous, 'epoch_id' => $nextId, 'parent_epoch' => $epochRef,
                    'logical_available_from' => Scope::day($day)->modify('+1 day')->format('Y-m-d'), 'history_event_end_exclusive' => Scope::day($day)->modify('+1 day')->format(DATE_ATOM),
                    'results' => [...$previous['results'], ...$newResults], 'inputs' => [...$previous['inputs'], ...$newInputs], 'created_at' => $this->now()]);
            }
            $dayRefs[$day] = $lockRef;
            foreach (['C', 'X', 'T'] as $name) {
                $candidateCounts[$name] += $lock['counts'][$name];
            }
            $this->pause('AFTER_DAY');
        }
        $index = [...$run, 'days' => $dayRefs, 'header' => $store->reference('run'), 'event' => 'RUN_INDEX'];
        if ($integrity !== null) {
            $integrity();
        }
        BundleRules::require(self::codeHash() === $identity['code_sha256'], 'Execution code changed before run completion.');
        $store->put('index-'.substr(Json::hash($dayRefs), 0, 20), 'validation_run', $index);

        return ['status' => 'OFFLINE_CONNECTION_COMPLETED', 'scope' => $manifest['scope'], 'summary' => [...$this->evaluator->combine($contributions), 'candidate_counts' => $candidateCounts], 'days' => $dayRefs, 'fit_count' => 0, 'real_execution_authorized' => false];
    }

    public function evaluation(Store $store, array $result, array $resultRef, string $id): array
    {
        if ($store->has($id)) {
            $ref = $store->reference($id);
            BundleRules::require($store->get($ref)['data']['result'] === $resultRef, 'Evaluation result version conflict.');

            return $ref;
        }
        $predictions = [];
        foreach ($result['predictions'] as $method => $ref) {
            $predictions[$method] = $store->get($ref)['data'];
        }
        $c = $this->evaluator->evaluate($predictions, $result);

        return $store->put($id, 'evaluation_bundle', ['evaluation_id' => $id, 'prediction_hash' => $result['predictions'][BundleRules::METHODS[0]]['manifest_sha256'],
            'comparison_prediction' => $result['predictions'][BundleRules::METHODS[1]], 'comparison_prediction_hash' => $result['predictions'][BundleRules::METHODS[1]]['manifest_sha256'],
            'result' => $resultRef, 'result_version' => $result['result_version'], 'metric_contract_version' => 'PRIMARY-TUPLE-COMMON-DENOMINATOR-v1',
            'paired_set_sha256' => Json::hash([$result['external_race_key'], $c['E_pair']]), 'contributions' => [$result['external_race_key'] => $c], 'metrics' => $c['methods'],
            'coverage' => $c['coverage'], 'excluded' => $c['excluded'], 'failed' => [], 'is_final' => $c['is_final'], 'contribution' => $c, 'created_at' => $this->now()]);
    }

    public function reproduce(Store $store): array
    {
        $count = 0;
        foreach ($store->all() as $ref) {
            $b = $store->get($ref);
            if ($b['kind'] !== 'evaluation_bundle') {
                continue;
            }
            $r = $store->get($b['data']['result'])['data'];
            $predictions = [];
            foreach ($r['predictions'] as $method => $p) {
                $predictions[$method] = $store->get($p)['data'];
            }
            BundleRules::require(Json::hash($this->evaluator->evaluate($predictions, $r)) === Json::hash($b['data']['contribution']), 'Saved evaluation reproduction mismatch.');
            $count++;
        }

        return ['status' => 'EVALUATIONS_REPRODUCED', 'evaluations' => $count, 'inference_count' => 0];
    }

    public function correct(Store $store, array $parentResult, array $correction, array $parentEpoch, string $futureDay): array
    {
        return $store->locked('run', function () use ($store, $parentResult, $correction, $parentEpoch, $futureDay): array {
            $run = $store->get($store->reference('run'))['data'];
            (new Scope(...$run['scope']))->target($futureDay);
            $old = $store->get($parentResult)['data'];
            Scope::day($futureDay);
            $id = 'd'.str_replace('-', '', $futureDay).'-corrected-epoch';
            BundleRules::require($futureDay > $old['race_date'] && ! $store->has('d'.str_replace('-', '', $futureDay).'-candidates')
                && $correction['official_status'] === 'CORRECTED' && $correction['result_version'] === $old['result_version'] + 1, 'Correction is not an unprocessed future version.');
            $prediction = $store->get($old['predictions'][BundleRules::METHODS[0]])['data'];
            $fixed = $store->get($prediction['candidate_ref']['manifest'])['data'];
            $candidates = array_values(array_filter($fixed['candidates'], fn (array $c): bool => $c['external_race_key'] === $old['external_race_key']));
            $audit = $this->matcher->match($candidates[0], $correction);
            $resultId = preg_replace('/-v\d+$/', '-v'.$correction['result_version'], $old['result_id']);
            $row = [...$old, ...$correction, 'result_id' => $resultId, 'parent_result' => $parentResult, 'identity_audit' => $audit, 'raw' => $correction['source_seal']];
            if ($row['history_event'] !== null) {
                $row['history_event']['race_status'] = 'CORRECTED';
                $row['history_event']['source_id'] = $correction['source_id'];
                $row['history_event']['fetched_at'] = $correction['fetched_at'];
                $row['history_event']['saved_at'] = $correction['saved_at'];
                $row['history_event']['logical_available_from'] = $futureDay;
                foreach ($row['history_event']['entries'] as &$entry) {
                    $actual = array_values(array_filter($correction['entries'], fn (array $e): bool => $e['bike'] === $entry['bike']))[0];
                    foreach (['rank', 'status', 'winning_technique'] as $field) {
                        $entry[$field] = $actual[$field];
                    }
                }
                unset($entry);
            }
            $resultRef = $store->put($resultId, 'result_bundle', $row);
            $evaluation = $this->evaluation($store, $row, $resultRef, $resultId.'-evaluation');
            $epoch = $store->get($parentEpoch)['data'];
            BundleRules::require(in_array($parentResult, $epoch['results'], true), 'Correction parent missing from fixed epoch.');
            $epoch['results'] = array_values(array_filter($epoch['results'], fn (array $ref): bool => $store->get($ref)['data']['external_race_key'] !== $old['external_race_key']));
            $epoch['results'][] = $resultRef;
            $epoch = [...$epoch, 'epoch_id' => $id, 'parent_epoch' => $parentEpoch, 'logical_available_from' => $futureDay, 'created_at' => $this->now()];

            return ['result' => $resultRef, 'evaluation' => $evaluation, 'epoch' => $store->put($id, 'history_epoch', $epoch)];
        });
    }

    public static function codeHash(): string
    {
        $files = glob(__DIR__.'/*.php');
        $files[] = dirname(__DIR__, 5).'/app/Console/Commands/Keirin/ChronologicalValidationCommand.php';
        $base = dirname(__DIR__, 5).'/app/Domain/Keirin/';
        foreach (['Statistics/DTO/*.php', 'Statistics/Support/*.php', 'Statistics/Calculators/Stat{01,07,08,10,11,12,23,24,26,31,32,39,42}Calculator.php',
            'Backtest/Calculators/Bt02SignalEligibilityEvaluator.php', 'Backtest/Calculators/Bt03e0{3,5,6}*.php', 'Backtest/Calculators/EffectBinBuilder.php', 'Backtest/Support/CanonicalHasher.php',
            'Backtest/Services/Bt0{2,3e02,3e03}*.php', 'Backtest/Experiments/TacticalHistory/{HistoryAggregator,Layout,Predictor}.php',
            'Scraping/Parsers/{RaceDetailParser,EmbeddedJsonExtractor}.php', 'Scraping/Support/{CharacterEncodingConverter,RaceCategoryPolicy}.php'] as $pattern) {
            $files = [...$files, ...glob($base.$pattern, GLOB_BRACE)];
        }
        $files = array_values(array_unique($files));
        sort($files);

        return Json::hash(array_map(fn (string $path): array => [substr($path, strlen(dirname(__DIR__, 5)) + 1), hash_file('sha256', $path)], $files));
    }

    private function now(): string
    {
        return $this->clock === null ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d\TH:i:s.uP') : ($this->clock)();
    }

    private function pause(string $stage): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($stage);
        }
    }
}
