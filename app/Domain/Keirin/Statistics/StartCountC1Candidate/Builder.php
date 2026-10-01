<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountC1Candidate;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use PDO;
use RuntimeException;

final class Builder
{
    public function __construct(private readonly Sources $sources = new Sources) {}

    public function build(string $output, ?string $original = null): array
    {
        $this->sources->protect($output);
        if ($original !== null && ($output === $original || str_starts_with($output.'/', $original.'/') || str_starts_with($original.'/', $output.'/'))) {
            throw new RuntimeException('Output overlaps original candidate.');
        }
        $source = $this->sources->open();
        $code = Contract::code();
        $prior = $original === null ? null : Bundle::open($original);
        Artifacts::create($output);
        try {
            $db = new Index($output.'/index.sqlite');
            $years = array_fill_keys(Contract::YEARS, self::counts());
            $invariance = $this->loadC1($db, $source, $years);
            $this->loadMapping($db);
            $links = new Stream($output.'/source-observation-links.jsonl');
            $this->loadFetches($db, $source, $years, $links);
            $this->loadObservations($db, $source, $years, $links);
            $this->loadUnresolved($db);
            $db->flush();
            if ((int) $db->run('SELECT COUNT(*) FROM fetches WHERE seen<>expected')->fetchColumn() !== 0) {
                throw new RuntimeException('Fetch audit / observation row count mismatch.');
            }
            $files = ['source-observation-links.jsonl' => $links->finish()];
            $timing = [];
            foreach (Contract::YEARS as $year) {
                $timing[$year] = ['observation_relations' => [], 's_aggregation_period_unknown' => 0,
                    's_statistical_as_of_unknown' => 0, 's_correction_as_of_unknown' => 0];
                $candidate = new Stream($output.'/candidates-'.$year.'.jsonl');
                $audit = new Stream($output.'/mapping-audit-'.$year.'.jsonl');
                $order = hash_init('sha256');
                foreach (Artifacts::lines($this->sources->path('c1', 'c1-'.$year.'.jsonl')) as $race) {
                    Validator::race($race, $year);
                    foreach ($race['entries'] as $entry) {
                        $identity = ['year' => $year, 'race_id' => $race['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike']];
                        hash_update($order, Files::canonical($identity)."\n");
                        [$record, $detail] = $this->candidate($db, $identity, $years[$year], $timing[$year]);
                        $candidate->row($record);
                        $audit->row($detail);
                    }
                }
                if (hash_final($order) !== $invariance[$year]['cohort_order_sha256']) {
                    throw new RuntimeException('C1 order changed during output.');
                }
                $files['candidates-'.$year.'.jsonl'] = $candidate->finish();
                $files['mapping-audit-'.$year.'.jsonl'] = $audit->finish();
            }
            $total = self::counts();
            foreach ($years as $year => &$counts) {
                $counts['outside_c1_unique_entries'] = (int) $db->run('SELECT COUNT(*) FROM outside WHERE substr(race,1,4)=?', [(string) $year])->fetchColumn();
                ksort($counts['hold_states'], SORT_STRING);
                ksort($counts['reasons'], SORT_STRING);
                if ($counts['entries'] !== $counts['numeric_candidates'] + $counts['null_candidates']
                    || $counts['source_rows'] !== $counts['linked_observations'] + $counts['outside_c1_observations']
                    || $counts['null_candidates'] !== array_sum($counts['hold_states'])) {
                    throw new RuntimeException('Candidate coverage reconciliation failed.');
                }
                foreach ($counts as $key => $value) {
                    if (is_int($value)) {
                        $total[$key] += $value;
                    } else {
                        foreach ($value as $reason => $n) {
                            $total[$key][$reason] = ($total[$key][$reason] ?? 0) + $n;
                        }
                        ksort($total[$key]);
                    }
                }
            }
            unset($counts, $db);
            unlink($output.'/index.sqlite');
            $coverage = ['status' => 'CANDIDATES_PREPARED_NOT_AUTHORIZED', 'years' => $years, 'total' => $total,
                'hold_states_exclusive' => true, 'reason_counts_nonexclusive' => true, ...Contract::restrictions()];
            $files['coverage.json'] = Artifacts::json($output, 'coverage.json', $coverage);
            $csv = "year,dimension,count\n";
            foreach (($years + ['TOTAL' => $total]) as $year => $counts) {
                foreach ($counts as $key => $n) {
                    if (is_int($n)) {
                        $csv .= "$year,$key,$n\n";
                    }
                }
            }
            $files['coverage.csv'] = Artifacts::write($output, 'coverage.csv', [$csv]);
            $files['timing-status.json'] = Artifacts::json($output, 'timing-status.json', ['years' => $timing,
                'target_date_timezone' => 'Asia/Tokyo', 'fetched_at_meaning' => 'SYSTEM_FETCH_TIME_NOT_S_CUTOFF',
                'aggregation_period' => null, 'statistical_as_of' => null, 'correction_as_of' => null, ...Contract::restrictions()]);
            $files['invariance.json'] = Artifacts::json($output, 'invariance.json', ['years' => $invariance,
                'c1_files_unmodified' => true, 'all_targets_order_and_types_preserved' => true,
                'c1_non_result_values_not_repacked_into_sidecar' => true]);
            $files['contract.json'] = Artifacts::json($output, 'contract.json', Contract::plan());
            Sources::verify($source);
            Files::same($code, Contract::code(), 'direct code START/END');
            $files['verification.json'] = Artifacts::json($output, 'verification.json', ['source_code_start_end_unchanged' => true,
                'all_read_files_sealed' => true, 'all_snapshot_rows_accounted_once' => true, 'fetch_row_counts_reconciled' => true,
                'training_read' => 'BLOCKED_INPUT_SEMANTICS', 'DB_HTTP_RAW_TRAINING_access' => 0, '2026_access' => 0]);
            $manifest = ['version' => Contract::VERSION, 'status' => 'CANDIDATES_PREPARED_NOT_AUTHORIZED',
                'restrictions' => Contract::restrictions(), 'source' => $source['pins'], 'code' => $code, 'files' => $files];
            if ($prior !== null) {
                Files::same($prior, $manifest, 'independent candidate reproduction');
                Files::same($prior, Bundle::open($original), 'original candidate START/END');
                Artifacts::json($output, 'reproduction.json', ['identical' => true, 'files' => array_keys($files), 'manifest_identical' => true]);
            }
            Artifacts::publish($output, $manifest);

            return ['path' => $output, 'manifest' => Files::identity($output.'/manifest.json'), 'coverage' => $coverage,
                'independent_reproduction' => $prior !== null];
        } catch (\Throwable $e) {
            Artifacts::json($output, 'FAILED.json', ['status' => 'FAILED_NOT_COMPLETE', 'error' => $e::class.': '.$e->getMessage()]);
            throw $e;
        }
    }

    private function loadC1(Index $db, array $source, array &$years): array
    {
        $invariance = [];
        foreach (Contract::YEARS as $year) {
            $hash = hash_init('sha256');
            $order = hash_init('sha256');
            foreach (Artifacts::lines($this->sources->path('c1', 'c1-'.$year.'.jsonl')) as $race) {
                Validator::race($race, $year);
                $db->write('INSERT INTO races VALUES (?,?)', [$race['race_id'], $year]);
                $years[$year]['races']++;
                hash_update($hash, Files::canonical($race)."\n");
                foreach ($race['entries'] as $entry) {
                    $db->write('INSERT INTO entries(id,year,race,bike) VALUES (?,?,?,?)', [$entry['id'], $year, $race['race_id'], $entry['bike']]);
                    $years[$year]['entries']++;
                    hash_update($order, Files::canonical(['year' => $year, 'race_id' => $race['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike']])."\n");
                }
            }
            $m = $source['manifests']['c1'];
            if ($years[$year]['races'] !== $m['source']['expected_rows'][$year] || $years[$year]['entries'] !== $m['source']['expected_targets'][$year]) {
                throw new RuntimeException('Fixed C1 cohort count mismatch.');
            }
            $invariance[$year] = ['c1_original_seal' => $m['files']['c1-'.$year.'.jsonl'],
                'non_result_semantic_sha256' => hash_final($hash), 'cohort_order_sha256' => hash_final($order),
                'races' => $years[$year]['races'], 'entries' => $years[$year]['entries']];
        }
        $db->flush();

        return $invariance;
    }

    private function loadMapping(Index $db): void
    {
        $line = 0;
        foreach (Artifacts::lines($this->sources->path('mapping', 'mapping-audit.jsonl')) as $m) {
            $line++;
            $reasons = Rows::mapping($m);
            $row = $db->entry($m['race_id'], $m['target']['bike']);
            if (! $row || (int) $row['id'] !== $m['entry_id'] || (int) $row['year'] !== $m['year'] || $row['mapping'] !== null) {
                throw new RuntimeException('Extra/duplicate/mismatched mapping target.');
            }
            $db->write('UPDATE entries SET mapping=? WHERE id=?', [Files::canonical(['mapping_line' => $line,
                'target' => $m['target'], 'context' => $m['context'], 'checks' => $m['checks'],
                'player_id_status' => $m['player_id_status'], 'race_class' => $m['race_class'], 'source_reasons' => $m['reasons']]), $m['entry_id']]);
            foreach ($reasons as $reason) {
                $db->reason($m['entry_id'], $reason);
            }
        }
        $db->flush();
        if ((int) $db->run('SELECT COUNT(*) FROM entries WHERE mapping IS NULL')->fetchColumn() !== 0) {
            throw new RuntimeException('Missing fixed C1 mapping targets.');
        }
        $conflicts = $db->run("SELECT race FROM entries e WHERE NOT EXISTS (SELECT 1 FROM reasons r WHERE r.entry=e.id)
            GROUP BY race HAVING COUNT(DISTINCT json_extract(mapping,'$.target.meeting_id') || ':' ||
            json_extract(mapping,'$.target.meeting_start') || ':' || json_extract(mapping,'$.target.meeting_end') || ':' ||
            json_extract(mapping,'$.target.race_date'))>1");
        while (($race = $conflicts->fetchColumn()) !== false) {
            $db->raceReason((int) $race, 'CONFLICTING_VERIFIED_MEETING_CONTEXT');
        }
        $db->write("CREATE TEMP TABLE duplicate_mapping_groups AS SELECT race, json_extract(mapping,'$.context.external_player_id') external
            FROM entries e WHERE NOT EXISTS (SELECT 1 FROM reasons r WHERE r.entry=e.id)
            GROUP BY race,external HAVING COUNT(*)>1");
        $db->write("INSERT OR IGNORE INTO reasons SELECT e.id,'DUPLICATE_VERIFIED_MAPPING_EXTERNAL_ID' FROM entries e
            JOIN duplicate_mapping_groups d ON d.race=e.race AND d.external=json_extract(e.mapping,'$.context.external_player_id')
            WHERE NOT EXISTS (SELECT 1 FROM reasons r WHERE r.entry=e.id)");
        $db->flush();
    }

    private function loadFetches(Index $db, array $source, array &$years, Stream $links): void
    {
        $line = 0;
        foreach (Artifacts::lines($this->sources->path('snapshots', 'fetch-audit.jsonl')) as $r) {
            $line++;
            $year = (int) substr($r['race']['race_date'] ?? '', 0, 4);
            Rows::base($r, $year);
            $failed = ($r['status'] ?? null) === 'FETCH_NOT_SUCCESSFUL';
            C1::keys($r, [...Rows::BASE, ...($failed ? ['status', 'rows'] : ['converted_sha256', 'page', 'rows'])]);
            if (! is_int($r['rows']) || $r['rows'] < 0 || ($failed && $r['rows'] !== 0)) {
                throw new RuntimeException('Invalid fetch row audit.');
            }
            if (! $failed) {
                C1::keys($r['page'], ['observed_race', 'issues', 'missing_bikes', ...(array_key_exists('summary_issues', $r['page']) ? ['summary_issues'] : [])]);
                foreach (['issues', 'missing_bikes', ...(isset($r['page']['summary_issues']) ? ['summary_issues'] : [])] as $key) {
                    if (! is_array($r['page'][$key]) || ! array_is_list($r['page'][$key])) {
                        throw new RuntimeException('Invalid fetch page audit list.');
                    }
                }
            }
            $db->write('INSERT INTO fetches(id,race,year,expected,data) VALUES (?,?,?,?,?)',
                [$r['fetch_log_id'], $r['race']['race_id'], $year, $r['rows'], Files::canonical($r)]);
            $years[$year]['fetch_versions']++;
            $links->row(['kind' => $failed ? 'FAILED_FETCH_NO_NUMERIC_OBSERVATION' : 'SUCCESSFUL_FETCH_VERSION',
                'year' => $year, 'race_id' => $r['race']['race_id'], 'fetch_log_id' => $r['fetch_log_id'],
                'source_file' => 'fetch-audit.jsonl', 'source_line' => $line,
                'source_file_sha256' => $source['manifests']['snapshots']['files']['fetch-audit.jsonl']['sha256'],
                'fetched_at' => $r['fetched_at'], 'fetched_at_meaning' => $r['fetched_at_meaning'],
                'fetched_date_relation' => Timing::relation($r['fetched_at'], $r['race']['race_date']),
                'raw_file_path' => $r['raw_file_path'], 'raw_sha256' => $r['original_sha256'],
                'rows' => $r['rows'], 'page' => $r['page'] ?? null, 'reason' => $r['status'] ?? null]);
            if ($failed) {
                $years[$year]['fetch_failures']++;
            } else {
                foreach ($r['page']['missing_bikes'] as $bike) {
                    $entry = $db->entry($r['race']['race_id'], $bike);
                    if ($entry) {
                        $db->reason((int) $entry['id'], 'SUCCESSFUL_VERSION_MISSING_ENTRY');
                    }
                }
                foreach ($r['page']['issues'] as $reason) {
                    $db->raceReason($r['race']['race_id'], 'PAGE_'.$reason);
                }
                if ($r['rows'] === 0) {
                    $db->raceReason($r['race']['race_id'], 'SUCCESSFUL_VERSION_NO_PARSABLE_ENTRIES');
                }
            }
        }
        $db->flush();
    }

    private function loadObservations(Index $db, array $source, array &$years, Stream $links): void
    {
        foreach (Contract::YEARS as $year) {
            $line = 0;
            foreach (Rows::observations($this->sources->path('snapshots', 'snapshots-'.$year.'.jsonl')) as $r) {
                $line++;
                Rows::snapshot($r, $year);
                $fetch = $db->run('SELECT * FROM fetches WHERE id=?', [$r['fetch_log_id']])->fetch(PDO::FETCH_ASSOC);
                if (! $fetch || (int) $fetch['race'] !== $r['race']['race_id'] || (int) $fetch['year'] !== $year || (int) $fetch['expected'] === 0) {
                    throw new RuntimeException('Observation not bound to successful fetch audit.');
                }
                $f = json_decode($fetch['data'], true, flags: JSON_THROW_ON_ERROR);
                foreach ([...Rows::BASE, 'converted_sha256'] as $key) {
                    if ($r[$key] !== $f[$key]) {
                        throw new RuntimeException('Fetch/observation provenance contradiction.');
                    }
                }
                $entry = $db->entry($r['race']['race_id'], $r['bike_number']);
                $claimed = $r['entry_id'] === null ? false : $db->run('SELECT * FROM entries WHERE id=?', [$r['entry_id']])->fetch(PDO::FETCH_ASSOC);
                if (! $entry && $claimed) {
                    $entry = $claimed;
                    $db->reason((int) $entry['id'], 'OBSERVATION_RACE_OR_BIKE_MISMATCH');
                }
                $reasons = [];
                if ($entry) {
                    $m = json_decode($entry['mapping'], true, flags: JSON_THROW_ON_ERROR);
                    if ($r['entry_id'] !== (int) $entry['id'] || $r['race']['race_date'] !== $m['target']['race_date']
                        || $r['ledger_external_id'] !== $m['context']['external_player_id']
                        || $r['observed_external_id'] !== $m['context']['external_player_id']
                        || $r['pc0201_external_id'] !== $m['context']['external_player_id']) {
                        $reasons[] = 'OBSERVATION_C1_IDENTITY_MISMATCH';
                    }
                    $observed = $r['observed_race'];
                    $digits = static fn ($v) => (is_int($v) || is_string($v)) && preg_match('/\\A[0-9]+\\z/D', (string) $v);
                    if (! $digits($observed['race_date']) || (string) $observed['race_date'] !== str_replace('-', '', $m['target']['race_date'])
                        || ! $digits($observed['track_code']) || (int) $observed['track_code'] !== (int) $r['race']['track_code']
                        || ! $digits($observed['race_number']) || (int) $observed['race_number'] !== $r['race']['race_number']
                        || ! $digits($r['observed_bike']) || (int) $r['observed_bike'] !== (int) $entry['bike']) {
                        $reasons[] = 'OBSERVATION_RACE_OR_BIKE_MISMATCH';
                    }
                    foreach ($r['identity_issues'] as $reason) {
                        $reasons[] = 'OBSERVATION_'.$reason;
                    }
                    if ($r['field']['status'] !== 'NUMERIC') {
                        $reasons[] = 'OBSERVATION_FIELD_'.$r['field']['status'];
                    }
                    foreach ($reasons as $reason) {
                        $db->reason((int) $entry['id'], $reason);
                    }
                    $years[$year]['linked_observations']++;
                } else {
                    $years[$year]['outside_c1_observations']++;
                    // Prefix the unique outside key with its partition; race IDs need not encode a year.
                    $db->write('INSERT OR IGNORE INTO outside VALUES (?,?,?)',
                        [$year.':'.$r['race']['race_id'], (string) $r['bike_number'], (string) $r['observed_external_id']]);
                }
                $ref = ['source_file' => 'snapshots-'.$year.'.jsonl', 'source_line' => $line, 'fetch_log_id' => $r['fetch_log_id'],
                    'row_index' => $r['row_index'], 'source_file_sha256' => $source['manifests']['snapshots']['files']['snapshots-'.$year.'.jsonl']['sha256'],
                    'raw_file_path' => $r['raw_file_path'], 'raw_sha256' => $r['original_sha256'], 'converted_sha256' => $r['converted_sha256'],
                    'source_pointer' => $r['source_pointer'], 'fetched_at' => $r['fetched_at'],
                    'fetched_date_relation' => Timing::relation($r['fetched_at'], $r['race']['race_date']),
                    'field' => $r['field'], 'value_signature' => $r['value_signature'], 'original_identity' => [
                        'race' => $r['race'], 'entry_id' => $r['entry_id'], 'bike' => $r['bike_number'],
                        'ledger_external_id' => $r['ledger_external_id'], 'observed_external_id' => $r['observed_external_id'],
                        'pc0201_external_id' => $r['pc0201_external_id']], 'identity_issues' => $r['identity_issues']];
                $db->write('INSERT INTO observations VALUES (?,?,?,?,?,?,?)', [$r['race']['race_id'], $r['fetch_log_id'], $r['row_index'],
                    $entry ? (int) $entry['id'] : null, $r['displayed_start_count'], $r['value_signature'], Files::canonical($ref)]);
                $db->write('UPDATE fetches SET seen=seen+1 WHERE id=?', [$r['fetch_log_id']]);
                $years[$year]['source_rows']++;
                $links->row(['kind' => $entry ? 'C1_OBSERVATION' : 'OUTSIDE_C1_COHORT', 'year' => $year,
                    'candidate_entry_id' => $entry ? (int) $entry['id'] : null, 'connection_reasons' => $reasons, ...$ref]);
            }
            $coverage = Files::json($this->sources->path('snapshots', 'coverage.json'));
            if ($line !== $coverage['years'][$year]['snapshot_rows']) {
                throw new RuntimeException('Source coverage/partition count mismatch.');
            }
        }
        $db->flush();
    }

    private function loadUnresolved(Index $db): void
    {
        foreach (Contract::YEARS as $year) {
            foreach (Artifacts::lines($this->sources->path('snapshots', 'unresolved-'.$year.'.jsonl')) as $r) {
                Rows::unresolved($r, $year);
                if (($r['reason'] ?? null) === 'FETCH_NOT_SUCCESSFUL' || ($r['reason'] ?? null) === 'NO_PJ0315_CANDIDATE') {
                    continue;
                }
                if (isset($r['bike_number'])) {
                    $entry = $db->entry($r['race']['race_id'], $r['bike_number']);
                    if ($entry) {
                        $db->reason((int) $entry['id'], 'UNRESOLVED_'.$r['reason']);
                    }
                } else {
                    $db->raceReason($r['race']['race_id'], 'UNRESOLVED_'.$r['reason']);
                }
            }
        }
        $db->flush();
    }

    private function candidate(Index $db, array $identity, array &$counts, array &$timing): array
    {
        $entry = $db->entry($identity['race_id'], $identity['bike']);
        if (! $entry || (int) $entry['id'] !== $identity['entry_id'] || (int) $entry['year'] !== $identity['year']) {
            throw new RuntimeException('C1 output universe changed.');
        }
        $m = json_decode($entry['mapping'], true, flags: JSON_THROW_ON_ERROR);
        $reasons = $db->reasons($identity['entry_id']);
        $stats = $db->run('SELECT COUNT(*) n, COUNT(value) numeric_n, COUNT(DISTINCT value) values_n, COUNT(DISTINCT signature) signatures_n, MIN(value) value FROM observations WHERE entry=?',
            [$identity['entry_id']])->fetch(PDO::FETCH_ASSOC);
        $n = (int) $stats['n'];
        if ($n === 0) {
            $reasons[] = 'NO_MATCHING_SNAPSHOT';
        }
        if ((int) $stats['values_n'] > 1) {
            $reasons[] = 'VALUE_CONFLICT';
        }
        sort($reasons, SORT_STRING);
        $reasons = array_values(array_unique($reasons));
        $connected = ! array_filter($reasons, fn ($r) => str_starts_with($r, 'UNVERIFIED_') || str_starts_with($r, 'MAPPING_')
            || str_contains($r, 'IDENTITY') || str_contains($r, 'MISMATCH') || str_contains($r, 'DUPLICATE_') || str_contains($r, 'CONFLICTING_VERIFIED_'));
        $value = $reasons === [] && (int) $stats['numeric_n'] === $n ? (int) $stats['value'] : null;
        $state = $value !== null ? 'UNIQUE_DISPLAY_VALUE' : (in_array('VALUE_CONFLICT', $reasons, true) ? 'VALUE_CONFLICT'
            : (! $connected ? 'IDENTITY_OR_CONTEXT_UNVERIFIED' : ($n === 0 ? 'NO_MATCHING_SNAPSHOT' : 'INCOMPLETE_OR_CONTRADICTORY_VERSIONS')));
        $counts['connected'] += (int) ($connected && $n > 0);
        $counts[$value === null ? 'null_candidates' : 'numeric_candidates']++;
        if ($value === null) {
            $counts['hold_states'][$state] = ($counts['hold_states'][$state] ?? 0) + 1;
        }
        foreach ($reasons as $reason) {
            $counts['reasons'][$reason] = ($counts['reasons'][$reason] ?? 0) + 1;
        }
        $counts['multiple_observation_versions'] += (int) ($n > 1);
        $counts['raw_type_or_representation_versions'] += (int) ((int) $stats['signatures_n'] > 1);
        $counts['value_conflicts'] += (int) ((int) $stats['values_n'] > 1);
        $counts['unknown_race_class_identity_connected'] += (int) ($m['race_class'] === 'UNKNOWN' && $connected && $n > 0);
        $representative = null;
        $observations = $db->run('SELECT ref FROM observations WHERE entry=? ORDER BY fetch,row_index', [$identity['entry_id']]);
        while (($ref = $observations->fetchColumn()) !== false) {
            $ref = json_decode($ref, true, flags: JSON_THROW_ON_ERROR);
            $representative ??= ['source_file' => $ref['source_file'], 'source_line' => $ref['source_line'],
                'fetch_log_id' => $ref['fetch_log_id'], 'row_index' => $ref['row_index']];
            $relation = $ref['fetched_date_relation'];
            $timing['observation_relations'][$relation] = ($timing['observation_relations'][$relation] ?? 0) + 1;
        }
        $failures = (int) $db->run('SELECT COUNT(*) FROM fetches WHERE race=? AND expected=0 AND json_extract(data,\'$.status\')=\'FETCH_NOT_SUCCESSFUL\'',
            [$identity['race_id']])->fetchColumn();
        $counts['entries_with_failed_fetch_history'] += (int) ($failures > 0);
        foreach (['s_aggregation_period_unknown', 's_statistical_as_of_unknown', 's_correction_as_of_unknown'] as $key) {
            $timing[$key]++;
        }
        ksort($timing['observation_relations'], SORT_STRING);
        $record = $identity + ['race_date' => $m['target']['race_date'], 'source' => 'keirin_jp',
            'external_player_id' => $m['context']['external_player_id'], 'candidate_displayed_start_count' => $value,
            'state' => $state, 'aggregation_period' => null, 'statistical_as_of' => null, 'correction_as_of' => null,
            'timing_status' => 'UNKNOWN_S_PERIOD_BASELINE_CORRECTION', ...Contract::restrictions()];
        $audit = $identity + ['mapping_source_line' => $m['mapping_line'], 'target' => $m['target'], 'context' => $m['context'],
            'checks' => $m['checks'], 'player_id_status' => $m['player_id_status'], 'race_class' => $m['race_class'],
            'mapping_source_reasons' => $m['source_reasons'], 'connected' => $connected && $n > 0,
            'state' => $state, 'reasons' => $reasons, 'observation_versions' => $n, 'numeric_versions' => (int) $stats['numeric_n'],
            'distinct_numeric_values' => (int) $stats['values_n'], 'distinct_raw_signatures' => (int) $stats['signatures_n'],
            'failed_fetch_versions_for_race' => $failures, 'representative_reference_only' => $representative];

        return [$record, $audit];
    }

    private static function counts(): array
    {
        return ['races' => 0, 'entries' => 0, 'connected' => 0, 'numeric_candidates' => 0, 'null_candidates' => 0,
            'source_rows' => 0, 'linked_observations' => 0, 'outside_c1_observations' => 0, 'outside_c1_unique_entries' => 0,
            'fetch_versions' => 0, 'fetch_failures' => 0, 'multiple_observation_versions' => 0,
            'raw_type_or_representation_versions' => 0, 'value_conflicts' => 0, 'unknown_race_class_identity_connected' => 0,
            'entries_with_failed_fetch_history' => 0, 'hold_states' => [], 'reasons' => []];
    }
}
