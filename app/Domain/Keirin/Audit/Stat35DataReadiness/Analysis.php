<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Generator;
use PDO;
use RuntimeException;

final class Analysis
{
    private PDO $spool;

    private IdentityAudit $identity;

    private array $coverage = [];

    private array $histograms = [];

    private array $signatures = [];

    private array $revisions = [];

    private array $firstLatest = ['compared_races' => 0, 'changed_races' => 0, 'compared_entries' => 0, 'changed_entries' => 0];

    private array $timing = ['before_start' => 0, 'after_start' => 0, 'equal_start' => 0, 'unknown' => 0];

    private array $quality = ['raw_result_pages_opened' => 0, 'result_status_rows_read' => 0, 'target_rank_semantic_reads' => 0,
        'target_winner_semantic_reads' => 0, 'predictive_metric_computations' => 0, '2026_access_count' => 0];

    public function __construct(private readonly ResultStore $writer, private readonly Extractor $extractor, private readonly RawReader $raw) {}

    public function run(string $directory, string $sourcePath, string $targetPath): array
    {
        $this->identity = new IdentityAudit;
        $this->spool = new PDO('sqlite:'.$directory.'/workspace.sqlite');
        $this->spool->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->spool->exec('PRAGMA cache_size=-4096');
        $this->spool->exec('CREATE TABLE races (id INTEGER PRIMARY KEY, data TEXT NOT NULL)');
        $this->spool->exec('CREATE TABLE entries (id INTEGER PRIMARY KEY, race_id INTEGER, bike INTEGER, player_id INTEGER)');
        $this->spool->exec('CREATE TABLE imports (id INTEGER PRIMARY KEY, race_id INTEGER, data TEXT NOT NULL)');
        $this->spool->exec('CREATE INDEX imports_race ON imports(race_id)');
        $this->spool->exec('CREATE TABLE extraction (race_id INTEGER, import_id INTEGER, bike INTEGER, player_id INTEGER, fetched INTEGER, data TEXT, PRIMARY KEY(import_id,bike))');
        $this->spool->exec('CREATE INDEX history_player_time ON extraction(player_id,fetched)');
        $this->spool->exec('CREATE TABLE targets (entry_id INTEGER PRIMARY KEY, race_id INTEGER, bike INTEGER, year INTEGER)');
        $insert = $this->spool->prepare('INSERT INTO targets VALUES (?,?,?,?)');
        $this->spool->beginTransaction();
        foreach (JsonlArtifact::read($targetPath) as $t) {
            if (! in_array($t['year'], [2024, 2025], true)) {
                throw new RuntimeException('Forbidden target year.');
            }
            $insert->execute([$t['entry_id'], $t['race_id'], $t['bike'], $t['year']]);
        }
        $this->spool->commit();
        $expected = $this->writer->writeJsonl($directory, 'agari-extraction.jsonl', $this->extract($sourcePath));
        $expected += $this->writer->writeJsonl($directory, 'raw-source-inventory.jsonl', $this->jsonRows('SELECT data FROM imports ORDER BY race_id,id'));
        $expected += $this->writer->writeJsonl($directory, 'race-import-summary.jsonl', $this->importSummaries($sourcePath));
        $expected += $this->writer->writeJsonl($directory, 'target-history-detail.jsonl', $this->targetDetails());
        $counts = [];
        foreach (['races', 'entries', 'imports', 'extraction', 'targets'] as $table) {
            $counts[$table] = (int) $this->spool->query('SELECT count(*) FROM '.$table)->fetchColumn();
        }
        foreach ($this->coverage as &$groups) {
            ksort($groups, SORT_STRING);
            foreach ($groups as &$values) {
                foreach (['raw_present_rate' => ['raw_files_present', 'result_imports'],
                    'raw_hash_verified_rate' => ['raw_hash_verified', 'result_imports'],
                    'normal_valid_agari_rate' => ['normal_valid_rows', 'normal_rows'],
                    'valid_agari_rate' => ['VALID', 'rows']] as $name => [$numerator, $denominator]) {
                    $values[$name] = ($values[$denominator] ?? 0) > 0 ? ($values[$numerator] ?? 0) / $values[$denominator] : null;
                }
                ksort($values);
            }
            unset($values);
        }
        unset($groups);
        ksort($this->signatures);
        ksort($this->revisions);
        $distribution = [];
        foreach ($this->histograms as $year => $histogram) {
            $distribution[$year] = self::distribution($histogram);
        }
        ksort($distribution);
        $all = $this->coverage['total']['ALL'] ?? [];
        $identity = $this->identity->artifact();
        $mapping = $identity['identity_blocker_total'];
        $errors = array_filter($all, fn ($key) => str_starts_with($key, 'error:'), ARRAY_FILTER_USE_KEY);
        $asOf = $this->historySummary($directory.'/target-history-detail.jsonl');
        $missingImports = (int) $this->spool->query('SELECT count(*) FROM races r WHERE NOT EXISTS (SELECT 1 FROM imports i WHERE i.race_id=r.id)')->fetchColumn();
        $decisionErrors = $errors;
        if ($missingImports > 0) {
            $decisionErrors['error:RAW_MISSING'] = ($decisionErrors['error:RAW_MISSING'] ?? 0) + $missingImports;
        }
        $blockers = self::blockers($mapping, $decisionErrors, $asOf['total_with_prior']);
        $ready = self::readiness($mapping, $decisionErrors, $asOf['total_with_prior']);
        $storageFeasible = ($all['parsed_imports'] ?? 0) > 0 && $identity['identity_safe'] && IdentityAudit::semanticErrors($errors) === 0;
        $importSummary = ['counts' => $counts, 'versions_per_race' => $this->spool->query('SELECT n AS versions,count(*) AS races FROM (SELECT count(*) n FROM imports GROUP BY race_id) GROUP BY n ORDER BY n')->fetchAll(PDO::FETCH_ASSOC),
            'fetched_vs_own_start' => $this->timing, 'all_versions_preserved' => true, 'canonical_version_selected' => false];
        $outputs = [
            'identity-mapping-audit.json' => $identity,
            'result-import-inventory.json' => $importSummary,
            'header-signatures.json' => $this->signatures,
            'coverage-by-year.json' => $this->coverage['year'] ?? [],
            'coverage-by-track.json' => $this->coverage['track'] ?? [],
            'coverage-by-grade-class.json' => $this->coverage['class'] ?? [],
            'coverage-by-meeting-grade.json' => $this->coverage['grade'] ?? [],
            'coverage-by-result-status.json' => $this->coverage['status'] ?? [],
            'coverage-total.json' => $all,
            'agari-value-distribution.json' => ['years' => $distribution, 'extreme_policy' => 'MIN_MAX_REVIEW_ONLY_NO_EMPIRICAL_EXCLUSION', 'unit' => 'RAW_AGARI_DECIMAL_SECONDS_NO_TRACK_DISTANCE_ADJUSTMENT'],
            'revision-audit.json' => ['transitions' => $this->revisions, 'order' => 'FETCHED_AT_THEN_IMPORT_ID_UNKNOWN_TIMING_LAST', 'comparison' => 'ALL_PARSEABLE_VERSIONS_BY_RACE_BIKE'],
            'first-latest-comparison.json' => $this->firstLatest,
            'target-history-coverage.json' => $asOf,
            'target-history-recency.json' => $asOf['recency'],
            'as-of-availability-audit.json' => ['counts' => $asOf['counts'], 'fetched_vs_own_start' => $this->timing,
                'cutoff' => 'STRICTLY_BEFORE_TARGET_EVENT_AND_FETCH', 'target_self_excluded' => true,
                'later_corrections_excluded' => true, 'publication_time' => 'PUBLICATION_TIME_UNKNOWN',
                'status_limitation' => 'CURRENT_DB_STATUS_QUALITY_ONLY_NOT_ASSUMED_AS_OF_OLDER_IMPORT',
                'coverage_policy' => 'VALID_FORMAT_PRIOR_AGARI_ALL_STATUSES_NOT_FINAL_STAT_ADMISSION_POLICY'],
            'data_quality_access_audit.json' => $this->quality + ['counting_scope' => 'ONE_ANALYSIS_PASS; RESULT_STATUS_COUNTS_UNIQUE_CAPTURED_DB_ROWS; RAW_COUNTS_IMPORT_REFERENCES',
                'integrity_passes' => 'ADDITIONAL_RAW_HASH_READS_RECORDED_IN_SOURCE_END; EXECUTE_DB_START_AND_END_EACH_READ_THE_CAPTURED_ROW_COUNT'],
            'leakage-audit.json' => ['target_outcome_policy' => 'TARGET_OUTCOME_NOT_ALLOWED_FOR_PREDICTIVE_ANALYSIS',
                'target_self_history' => 0, 'future_import_history' => 0, '2026_access_count' => 0,
                'rank_semantic_reads' => 0, 'winner_semantic_reads' => 0, 'predictive_metric_computations' => 0],
            'backfill-feasibility.json' => ['existing_raw_extractable_imports' => $all['parsed_imports'] ?? 0,
                'imports' => $counts['imports'], 'raw_gap_imports' => $all['raw_missing'] ?? 0,
                'raw_missing_import_rate' => $counts['imports'] > 0 ? ($all['raw_missing'] ?? 0) / $counts['imports'] : null,
                'refetch_required_rate' => ($all['raw_missing'] ?? 0) === 0 ? 0 : null,
                'refetch_reason' => ($all['raw_missing'] ?? 0) === 0 ? 'NO_PHYSICALLY_MISSING_RAW' : 'RAW_GAPS_IDENTIFIED_BUT_REFETCH_SUCCESS_NOT_ESTABLISHED',
                'errors' => $errors, 'identity_safe' => $identity['identity_safe'], 'as_of_targets_with_prior' => $asOf['total_with_prior'],
                'storage_backfill_feasible' => $storageFeasible,
                'storage_scope' => 'VERIFIED_EXISTING_RAW_ONLY_NOT_ALL_RACES_OR_AUTHORIZATION',
                'races_without_import' => $missingImports,
                'historical_as_of_backtest_feasible' => $storageFeasible && $asOf['total_with_prior'] > 0,
                'backfill_not_executed' => true],
            'readiness-decision.json' => ['status' => $ready, 'storage_extractability' => ($all['parsed_imports'] ?? 0) > 0,
                'identity_safe' => $identity['identity_safe'], 'identity_blocker_total' => $mapping,
                'historical_as_of_coverage_present' => $asOf['total_with_prior'] > 0,
                'historical_as_of_target_count' => $asOf['total_with_prior'],
                'primary_blocker' => $blockers[0] ?? null, 'secondary_blockers' => array_slice($blockers, 1),
                'predictive_improvement' => 'NOT_EVALUATED', 'next_implementation' => 'NOT_AUTHORIZED'],
        ];
        foreach ($outputs as $name => $data) {
            $expected += $this->writer->writeJson($directory, $name, $data);
        }
        unset($this->spool);
        if (! unlink($directory.'/workspace.sqlite')) {
            throw new RuntimeException('Cannot remove completed private workspace.');
        }

        return ['expected' => $expected, 'summary' => $outputs['readiness-decision.json'], 'counts' => $counts];
    }

    private function extract(string $path): Generator
    {
        $insertRace = $this->spool->prepare('INSERT INTO races VALUES (?,?)');
        $insertEntry = $this->spool->prepare('INSERT INTO entries VALUES (?,?,?,?)');
        $insertImport = $this->spool->prepare('INSERT INTO imports VALUES (?,?,?)');
        $insertValue = $this->spool->prepare('INSERT INTO extraction VALUES (?,?,?,?,?,?)');
        foreach (JsonlArtifact::read($path) as $source) {
            $race = $source['race'];
            Contract::date($race['race_date']);
            $groups = ['total' => 'ALL', 'year' => substr($race['race_date'], 0, 4), 'track' => substr($race['race_date'], 0, 4).':'.$race['track_code'],
                'class' => substr($race['race_date'], 0, 4).':'.Classification::raceClass($race['race_type']),
                'grade' => substr($race['race_date'], 0, 4).':'.Classification::normalize($race['meeting_grade'])];
            $this->increment($groups, 'target_races');
            $this->increment($groups, 'current_result_rows', count($source['results']));
            $this->quality['result_status_rows_read'] += count($source['results']);
            $this->spool->beginTransaction();
            $insertRace->execute([$race['race_id'], Files::canonical($race)]);
            foreach ($source['entries'] as $entry) {
                $insertEntry->execute([$entry['id'], $race['race_id'], $entry['bike_number'], $entry['player_id']]);
            }
            $previous = $first = $latest = [];
            usort($source['imports'], fn ($a, $b) => [($a['fetched_at'] === null ? PHP_INT_MAX : (new History)->time($a['fetched_at'])), $a['import_id']]
                <=> [($b['fetched_at'] === null ? PHP_INT_MAX : (new History)->time($b['fetched_at'])), $b['import_id']]);
            foreach ($source['imports'] as $import) {
                $this->increment($groups, 'result_imports');
                $record = $import + ['scheduled_start_at' => $race['scheduled_start_at']];
                $start = (new History)->time($race['scheduled_start_at']);
                $fetched = (new History)->time($import['fetched_at']);
                $this->timing[$start === null || $fetched === null ? 'unknown' : ($fetched < $start ? 'before_start' : ($fetched === $start ? 'equal_start' : 'after_start'))]++;
                if (! file_exists($import['absolute_path']) && ! is_link($import['absolute_path'])) {
                    $record['extraction_status'] = 'RAW_MISSING';
                    $this->increment($groups, 'raw_missing');
                    $this->increment($groups, 'error:RAW_MISSING');
                    $insertImport->execute([$import['import_id'], $race['race_id'], Files::canonical($record)]);

                    continue;
                }
                $this->increment($groups, 'raw_files_present');
                // Integrity failures are fatal; only structural parse gaps are recorded per page.
                $html = $this->raw->read($import);
                $this->quality['raw_result_pages_opened']++;
                $record['raw_sha256'] = $import['source_hash'];
                $record['raw_bytes'] = $import['raw_response_size'];
                $this->increment($groups, 'raw_hash_verified');
                try {
                    $parsed = $this->extractor->parse($html, $race, $source['entries'], $source['results'], $import['parsed_page_status']);
                } catch (RuntimeException $error) {
                    $record['extraction_status'] = $error->getMessage();
                    $this->increment($groups, 'error:'.$error->getMessage());
                    $this->identity->error($error->getMessage());
                    $insertImport->execute([$import['import_id'], $race['race_id'], Files::canonical($record)]);

                    continue;
                }
                if (isset($parsed['cancelled'])) {
                    $audit = $parsed['cancelled'];
                    $record['extraction_status'] = $audit['status'];
                    $record['header_signature'] = $parsed['header_signature'];
                    $record['cancelled_audit'] = $audit;
                    $this->identity->cancelled($audit);
                    if ($audit['status'] === 'EXPLICIT_CANCELLED_NO_RESULT_ROWS') {
                        $this->increment($groups, 'cancelled_imports_without_rows');
                    } elseif ($audit['status'] === 'EXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI') {
                        $this->increment($groups, 'cancelled_partial_imports');
                        $this->increment($groups, 'cancelled_partial_rows', $audit['partial_rows']);
                        $this->increment($groups, 'cancelled_partial_nonempty_agari_rows', $audit['nonempty_agari_rows']);
                    } else {
                        $this->increment($groups, 'error:'.$audit['status']);
                    }
                    $insertImport->execute([$import['import_id'], $race['race_id'], Files::canonical($record)]);

                    continue;
                }
                $record['extraction_status'] = 'PARSED';
                $record['header_signature'] = $parsed['header_signature'];
                $insertImport->execute([$import['import_id'], $race['race_id'], Files::canonical($record)]);
                $this->increment($groups, 'parsed_imports');
                foreach (['result_table_present', 'header_recognized', 'bike_identity_matched'] as $field) {
                    $this->increment($groups, $field);
                }
                $sig = $parsed['header_signature'];
                $this->signatures[$sig]['headers'] = $parsed['headers'];
                $this->signatures[$sig]['pages'] = ($this->signatures[$sig]['pages'] ?? 0) + 1;
                $this->signatures[$sig]['years'][$groups['year']] = true;
                $this->signatures[$sig]['tracks'][$race['track_code']] = true;
                foreach ($parsed['rows'] as $value) {
                    $statusGroups = $groups + ['status' => $groups['year'].':'.$value['result_status']];
                    $this->increment($statusGroups, 'rows');
                    $this->increment($statusGroups, $value['agari_status']);
                    if ($value['raw_agari_text'] !== null && $value['raw_agari_text'] !== '') {
                        $this->increment($statusGroups, 'agari_present');
                    }
                    if (in_array($value['result_status'], ['FINISHED', 'TIED'], true)) {
                        $this->increment($groups, 'normal_rows');
                        if ($value['agari_status'] === 'VALID') {
                            $this->increment($groups, 'normal_valid_rows');
                        }
                    }
                    if ($value['player_id'] === null) {
                        $this->increment($groups, 'unresolved_player_rows');
                        $this->identity->unresolvedExtracted();
                    }
                    $row = ['race_id' => $race['race_id'], 'import_id' => $import['import_id'], 'race_date' => $race['race_date'],
                        'scheduled_start_at' => $race['scheduled_start_at'], 'meeting_id' => $race['meeting_id'], 'fetched_at' => $import['fetched_at']] + $value;
                    $insertValue->execute([$race['race_id'], $import['import_id'], $value['bike_number'], $value['player_id'], $fetched, Files::canonical($row)]);
                    $bike = $value['bike_number'];
                    if (isset($previous[$bike])) {
                        $reason = self::revision($previous[$bike], $value);
                        $this->revisions[$reason] = ($this->revisions[$reason] ?? 0) + 1;
                    }
                    $previous[$bike] = $value;
                    if ($value['agari_status'] === 'VALID') {
                        $first[$bike] ??= $value;
                        $latest[$bike] = $value;
                        $v = $value['normalized_agari_seconds'];
                        $this->histograms[$groups['year']][$v] = ($this->histograms[$groups['year']][$v] ?? 0) + 1;
                    }
                    yield $row;
                }
            }
            if ($first !== []) {
                $this->firstLatest['compared_races']++;
                $changed = false;
                foreach ($first as $bike => $value) {
                    $this->firstLatest['compared_entries']++;
                    if (self::decimal($value['normalized_agari_seconds']) !== self::decimal($latest[$bike]['normalized_agari_seconds'])) {
                        $this->firstLatest['changed_entries']++;
                        $changed = true;
                    }
                }
                $this->firstLatest['changed_races'] += (int) $changed;
            }
            $this->spool->commit();
        }
        foreach ($this->signatures as &$s) {
            ksort($s['years']);
            ksort($s['tracks']);
            $s['track_count'] = count($s['tracks']);
        }
    }

    public static function revision(array $a, array $b): string
    {
        if ($a['header_signature'] !== $b['header_signature']) {
            return 'HEADER_CHANGED';
        }
        if ($a['agari_status'] !== 'VALID' && $b['agari_status'] === 'VALID') {
            return 'VALUE_ADDED';
        }
        if ($a['agari_status'] === 'VALID' && $b['agari_status'] !== 'VALID') {
            return 'VALUE_REMOVED';
        }
        if ($a['agari_status'] === 'VALID' && $b['agari_status'] === 'VALID'
            && self::decimal($a['normalized_agari_seconds']) !== self::decimal($b['normalized_agari_seconds'])) {
            return 'VALUE_CHANGED';
        }

        return $a['raw_agari_text'] !== $b['raw_agari_text'] ? 'FORMAT_CHANGED' : 'UNCHANGED';
    }

    private static function decimal(string $value): string
    {
        $parts = explode('.', $value);
        $integer = ltrim($parts[0], '0');
        $fraction = rtrim($parts[1] ?? '', '0');

        return ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);
    }

    public static function readiness(int $identityErrors, array $parseErrors, int $targetsWithPrior): string
    {
        return match (self::blockers($identityErrors, $parseErrors, $targetsWithPrior)[0] ?? null) {
            'IDENTITY_MAPPING' => 'BLOCKED_IDENTITY_MAPPING',
            'AMBIGUOUS_AGARI_SEMANTICS' => 'BLOCKED_AMBIGUOUS_AGARI_SEMANTICS',
            'INSUFFICIENT_RAW_HISTORY' => 'BLOCKED_INSUFFICIENT_RAW_HISTORY',
            'RAW_GAP_POLICY' => 'PARTIAL_READY_REQUIRES_RAW_GAP_POLICY',
            default => 'STRUCTURALLY_READY_FOR_STAT35_STORAGE_BACKFILL_REVIEW',
        };
    }

    private static function blockers(int $identityErrors, array $parseErrors, int $targetsWithPrior): array
    {
        $blockers = [];
        if ($identityErrors > 0) {
            $blockers[] = 'IDENTITY_MAPPING';
        }
        if (IdentityAudit::semanticErrors($parseErrors) > 0) {
            $blockers[] = 'AMBIGUOUS_AGARI_SEMANTICS';
        }
        if ($targetsWithPrior === 0) {
            $blockers[] = 'INSUFFICIENT_RAW_HISTORY';
        }
        if (($parseErrors['error:RAW_MISSING'] ?? 0) > 0) {
            $blockers[] = 'RAW_GAP_POLICY';
        }

        return $blockers;
    }

    private function targetDetails(): Generator
    {
        $query = $this->spool->query('SELECT t.*,e.player_id,e.race_id AS stored_race,e.bike AS stored_bike,r.data FROM targets t LEFT JOIN entries e ON e.id=t.entry_id LEFT JOIN races r ON r.id=t.race_id ORDER BY t.year,t.race_id,t.bike');
        $history = $this->spool->prepare('SELECT data FROM extraction WHERE player_id=? AND fetched < ? ORDER BY race_id,fetched,import_id');
        $policy = new History;
        while ($t = $query->fetch(PDO::FETCH_ASSOC)) {
            if ($t['stored_race'] !== $t['race_id'] || $t['stored_bike'] !== $t['bike'] || $t['data'] === null) {
                throw new RuntimeException('Fixed target identity mismatch.');
            }
            $target = json_decode($t['data'], true, flags: JSON_THROW_ON_ERROR) + ['player_id' => $t['player_id']];
            if ((int) substr($target['race_date'], 0, 4) !== $t['year']) {
                throw new RuntimeException('Target year mismatch.');
            }
            $start = $policy->time($target['scheduled_start_at']);
            if ($t['player_id'] === null) {
                $this->identity->unresolvedTarget();
            } else {
                $history->execute([$t['player_id'], $start]);
            }
            $rows = (function () use ($history): Generator {
                while ($json = $history->fetchColumn()) {
                    yield json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                }
            })();
            $chosen = $t['player_id'] === null ? [] : $policy->latest($rows, $target);
            $pre = $in = 0;
            $last = null;
            $normalAsOf = 0;
            foreach ($chosen as $h) {
                if ($h['kind'] === 'IN_MEETING') {
                    $in++;
                } else {
                    $pre++;
                    $last = max($last ?? PHP_INT_MIN, $policy->time($h['row']['scheduled_start_at']));
                    if ($h['row']['import_id'] === $h['row']['status_import_id'] && in_array($h['row']['result_status'], ['FINISHED', 'TIED'], true)) {
                        $normalAsOf++;
                    }
                }
            }
            yield ['year' => $t['year'], 'race_id' => $t['race_id'], 'entry_id' => $t['entry_id'], 'bike' => $t['bike'],
                'pre_meeting_count' => $pre, 'in_meeting_count' => $in, 'pre_meeting_confirmed_normal_status_count' => $normalAsOf,
                'recency_days' => $last === null ? null : ($start - $last) / 86400,
                'timing' => $start === null ? 'UNKNOWN_TARGET_START' : 'KNOWN_TARGET_START',
                'identity' => $t['player_id'] === null ? 'UNRESOLVED_PLAYER' : 'RESOLVED_PLAYER',
                'left_truncation' => 'LEFT_TRUNCATED_POSSIBLE', 'publication_time' => 'PUBLICATION_TIME_UNKNOWN'];
        }
    }

    private function historySummary(string $path): array
    {
        $years = $hist = $recent = [];
        $total = 0;
        foreach (JsonlArtifact::read($path) as $row) {
            $y = $row['year'];
            $years[$y]['target_entries'] = ($years[$y]['target_entries'] ?? 0) + 1;
            $n = $row['pre_meeting_count'];
            foreach ([0, 1, 3, 5, 10] as $cut) {
                $key = $cut === 0 ? 'zero' : 'at_least_'.$cut;
                $years[$y][$key] = ($years[$y][$key] ?? 0) + (int) ($cut === 0 ? $n === 0 : $n >= $cut);
            }
            $total += (int) ($n > 0);
            $years[$y]['with_in_meeting'] = ($years[$y]['with_in_meeting'] ?? 0) + (int) ($row['in_meeting_count'] > 0);
            $hist[$y][(string) $n] = ($hist[$y][(string) $n] ?? 0) + 1;
            foreach ([30, 60, 90, 180, 365] as $days) {
                $years[$y]['within_'.$days.'_days'] = ($years[$y]['within_'.$days.'_days'] ?? 0) + (int) ($row['recency_days'] !== null && $row['recency_days'] <= $days);
            }
            if ($row['recency_days'] !== null) {
                $k = (string) $row['recency_days'];
                $recent[$y][$k] = ($recent[$y][$k] ?? 0) + 1;
            }
        }
        $recency = [];
        foreach ($years as $year => &$data) {
            $data['history_count_distribution'] = self::distribution($hist[$year]);
            $recency[$year] = self::distribution($recent[$year] ?? []);
        }

        return ['counts' => $years, 'total_with_prior' => $total, 'recency' => $recency, 'scope' => 'PRE_MEETING_PRIMARY_IN_MEETING_SEPARATE'];
    }

    public static function distribution(array $hist): array
    {
        uksort($hist, fn ($a, $b) => (float) $a <=> (float) $b);
        $n = array_sum($hist);
        $out = ['n' => $n, 'min' => $n ? (string) array_key_first($hist) : null, 'max' => $n ? (string) array_key_last($hist) : null];
        foreach ([1, 5, 10, 25, 50, 75, 90, 95, 99] as $p) {
            $h = ($n - 1) * $p / 100;
            $lo = (int) floor($h);
            $hi = (int) ceil($h);
            $a = $b = null;
            $offset = 0;
            foreach ($hist as $v => $count) {
                if ($lo >= $offset && $lo < $offset + $count) {
                    $a = (float) $v;
                }
                if ($hi >= $offset && $hi < $offset + $count) {
                    $b = (float) $v;
                    break;
                }
                $offset += $count;
            }
            $out['P'.$p] = $n ? $a + ($h - $lo) * ($b - $a) : null;
        }
        $digits = [];
        foreach ($hist as $value => $count) {
            $d = str_contains((string) $value, '.') ? strlen(explode('.', (string) $value)[1]) : 0;
            $digits[$d] = ($digits[$d] ?? 0) + $count;
        }
        ksort($digits);
        $out['decimal_places'] = $digits;

        return $out;
    }

    private function increment(array $groups, string $field, int $n = 1): void
    {
        foreach ($groups as $dimension => $key) {
            $this->coverage[$dimension][$key][$field] = ($this->coverage[$dimension][$key][$field] ?? 0) + $n;
        }
    }

    private function jsonRows(string $sql): Generator
    {
        $q = $this->spool->query($sql);
        while ($value = $q->fetchColumn()) {
            yield json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    private function importSummaries(string $source): Generator
    {
        foreach (JsonlArtifact::read($source) as $row) {
            $imports = $row['imports'];
            usort($imports, fn ($a, $b) => [($a['fetched_at'] === null ? PHP_INT_MAX : (new History)->time($a['fetched_at'])), $a['import_id']]
                <=> [($b['fetched_at'] === null ? PHP_INT_MAX : (new History)->time($b['fetched_at'])), $b['import_id']]);
            yield ['race_id' => $row['race']['race_id'], 'race_date' => $row['race']['race_date'],
                'import_count' => count($imports), 'first_import_id' => $imports[0]['import_id'] ?? null,
                'last_import_id' => $imports === [] ? null : $imports[array_key_last($imports)]['import_id'],
                'source_hash_count' => count(array_unique(array_column($imports, 'source_hash'))),
                'parser_version_count' => count(array_unique(array_column($imports, 'parser_version'))),
                'order' => 'FETCHED_AT_THEN_IMPORT_ID_UNKNOWN_TIMING_LAST'];
        }
    }
}
