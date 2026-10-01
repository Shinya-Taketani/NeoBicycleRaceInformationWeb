<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\LineWriter;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use RuntimeException;
use Throwable;

final class Builder
{
    public function __construct(private readonly Ledger $ledger, private readonly Parser $parser, private readonly RawReader $raw) {}

    public function build(string $source, string $output, ?string $original = null): array
    {
        foreach (array_filter([$source, $original]) as $protected) {
            if ($output === $protected || str_starts_with($output.'/', $protected.'/') || str_starts_with($protected.'/', $output.'/')) {
                throw new RuntimeException('Output overlaps an existing input/artifact.');
            }
        }
        $input = Bundle::verify($source, 'SOURCE');
        $code = Bundle::code();
        $prior = $original === null ? null : Bundle::verify($original, 'SNAPSHOTS');
        if ($prior !== null) {
            Files::same($prior['source'], Files::identity($source.'/manifest.json'), 'reproduction source');
            Files::same($prior['code'], $code, 'reproduction code');
        }
        Artifacts::create($output);
        try {
            $index = new Index($output.'/index.sqlite');
            $index->load($source, $input, $this->ledger);
            $writers = [];
            $years = [];
            foreach (Contract::YEARS as $year) {
                foreach (['snapshots', 'unresolved'] as $kind) {
                    $writers[$kind.'-'.$year.'.jsonl'] = new LineWriter($output.'/'.$kind.'-'.$year.'.jsonl');
                }
                $years[$year] = ['target_races' => 0, 'target_entries' => 0, 'candidate_races' => 0, 'no_candidate_races' => 0,
                    'matched_races' => 0, 'unresolved_candidate_races' => 0, 'fetch_versions' => 0, 'snapshot_rows' => 0,
                    'zero' => 0, 'positive' => 0, 'missing' => 0, 'invalid' => 0, 'identity_mismatch' => 0,
                    'numeric_period_unknown' => 0, 'unresolved_reasons' => []];
            }
            $writers['fetch-audit.jsonl'] = new LineWriter($output.'/fetch-audit.jsonl');
            $sources = Artifacts::lines($source.'/sources.jsonl');
            $examples = [];
            foreach (Artifacts::lines($source.'/targets.jsonl') as $target) {
                $race = $target['race'];
                $year = (int) substr($race['race_date'], 0, 4);
                $y = &$years[$year];
                $y['target_races']++;
                $y['target_entries'] += count($target['entries']);
                $versions = 0;
                $matched = false;
                $lastFetch = 0;
                if ($sources->valid() && $sources->current()['race_id'] < $race['race_id']) {
                    throw new RuntimeException('Source outside target or unsorted.');
                }
                while ($sources->valid() && $sources->current()['race_id'] === $race['race_id']) {
                    $fetch = $sources->current();
                    if (! is_int($fetch['id']) || $fetch['id'] <= $lastFetch) {
                        throw new RuntimeException('Duplicate/unsorted response version.');
                    }
                    $lastFetch = $fetch['id'];
                    $versions++;
                    $y['fetch_versions']++;
                    $index->candidate($fetch);
                    $base = ['source' => 'keirin_jp', 'fetch_log_id' => $fetch['id'], 'race' => $race,
                        'fetched_at' => $fetch['fetched_at'], 'fetched_at_meaning' => 'SYSTEM_FETCH_TIME',
                        'source_parser_version' => $fetch['parser_version'], 'version' => Contract::VERSION,
                        'raw_file_path' => $fetch['raw_file_path'], 'original_sha256' => $fetch['sha256']];
                    if ($fetch['http_status'] !== 200 || $fetch['error_type'] !== null || ! $fetch['utf8_conversion_succeeded']) {
                        $this->unresolved($writers, $y, $year, $base + ['reason' => 'FETCH_NOT_SUCCESSFUL']);
                        $writers['fetch-audit.jsonl']->append($base + ['status' => 'FETCH_NOT_SUCCESSFUL', 'rows' => 0]);
                        $sources->next();

                        continue;
                    }
                    $html = $this->raw->read(self::rawSource($input, $fetch, $race));
                    $base['converted_sha256'] = hash('sha256', $html);
                    $page = $this->parser->parse($html, $target);
                    unset($html);
                    $writers['fetch-audit.jsonl']->append($base + ['page' => array_diff_key($page, ['rows' => true]), 'rows' => count($page['rows'])]);
                    if ($page['rows'] === []) {
                        $this->unresolved($writers, $y, $year, $base + ['reason' => 'NO_PARSABLE_ENTRIES', 'issues' => $page['issues']]);
                    }
                    foreach ($page['summary_issues'] ?? [] as $issue) {
                        $this->unresolved($writers, $y, $year, $base + $issue);
                    }
                    foreach ($page['missing_bikes'] as $bike) {
                        $this->unresolved($writers, $y, $year, $base + ['bike_number' => $bike, 'reason' => 'MISSING_ENTRY']);
                    }
                    foreach ($page['rows'] as $row) {
                        $y['snapshot_rows']++;
                        if ($row['identity_issues'] !== []) {
                            $category = 'identity_mismatch';
                        } else {
                            $matched = true;
                            $category = $row['displayed_start_count'] !== null ? ($row['displayed_start_count'] === 0 ? 'zero' : 'positive')
                                : (in_array($row['field']['status'], ['INVALID_FORMAT', 'OUT_OF_RANGE'], true) ? 'invalid' : 'missing');
                        }
                        $y[$category]++;
                        if (in_array($category, ['zero', 'positive'], true)) {
                            $y['numeric_period_unknown']++;
                        }
                        $record = $base + $row + ['observed_race' => $page['observed_race'],
                            'aggregation_period' => null, 'statistical_as_of' => null, 'correction_as_of' => null,
                            'timing_status' => 'UNKNOWN_NO_S_SPECIFIC_PERIOD_EVIDENCE',
                            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'points' => null];
                        $writers['snapshots-'.$year.'.jsonl']->append($record);
                        $index->record($year, $race['race_id'], $row);
                        foreach ($row['identity_issues'] ?: ($row['displayed_start_count'] === null ? [$row['field']['status']] : []) as $reason) {
                            $this->unresolved($writers, $y, $year, $base + ['row_index' => $row['row_index'], 'bike_number' => $row['bike_number'], 'reason' => $reason]);
                        }
                        $key = $year.':'.$category.':'.$row['field']['status'];
                        if (! isset($examples[$key])) {
                            $examples[$key] = $record;
                        }
                    }
                    $sources->next();
                }
                $y[$versions > 0 ? 'candidate_races' : 'no_candidate_races']++;
                if ($versions === 0) {
                    $this->unresolved($writers, $y, $year, ['race' => $race, 'reason' => 'NO_PJ0315_CANDIDATE']);
                } else {
                    $y[$matched ? 'matched_races' : 'unresolved_candidate_races']++;
                }
                unset($y);
            }
            if ($sources->valid()) {
                throw new RuntimeException('Source outside target.');
            }
            $files = [];
            foreach ($writers as $name => $writer) {
                $files[$name] = $writer->finish();
            }
            foreach ($years as $year => &$y) {
                $y += $index->distinct($year);
                ksort($y['unresolved_reasons'], SORT_STRING);
                if ($y['snapshot_rows'] !== $y['zero'] + $y['positive'] + $y['missing'] + $y['invalid'] + $y['identity_mismatch']
                    || $y['target_races'] !== $y['candidate_races'] + $y['no_candidate_races']) {
                    throw new RuntimeException('Coverage reconciliation failed.');
                }
            }
            unset($y, $index);
            $files['contract.json'] = Artifacts::json($output, 'contract.json', Contract::plan());
            $files['coverage.json'] = Artifacts::json($output, 'coverage.json', ['years' => $years, 'reason_counts_may_overlap' => true]);
            $csv = "year,dimension,count\n";
            foreach ($years as $year => $counts) {
                foreach ($counts as $key => $n) {
                    if (is_int($n)) {
                        $csv .= "$year,$key,$n\n";
                    }
                }
            }
            $files['coverage.csv'] = Artifacts::write($output, 'coverage.csv', [$csv]);
            ksort($examples, SORT_STRING);
            $files['examples.json'] = Artifacts::json($output, 'examples.json', $examples);
            Bundle::verify($source, 'SOURCE');
            Files::same($code, Bundle::code(), 'code START/END');
            $files['verification.json'] = Artifacts::json($output, 'verification.json', ['target_ledger_verified' => true,
                'all_read_raw_hashes_verified' => true, 'source_code_end_unchanged' => true,
                'application_db_http_access' => 0, '2026_race_access' => 0, 'prediction_use' => 'NOT_AUTHORIZED']);
            $manifest = ['version' => Contract::VERSION, 'kind' => 'SNAPSHOTS', 'source' => Files::identity($source.'/manifest.json'),
                'code' => $code, 'files' => $files];
            if ($prior !== null) {
                Files::same($prior, $manifest, 'independent snapshot reproduction');
                Bundle::verify($original, 'SNAPSHOTS');
            }
            Artifacts::publish($output, $manifest);
            unlink($output.'/index.sqlite');

            return ['path' => $output, 'manifest' => Files::identity($output.'/manifest.json'), 'years' => $years,
                'independent_reproduction' => $prior !== null];
        } catch (Throwable $e) {
            Artifacts::json($output, 'failure.json', ['status' => 'FAILED_NOT_COMPLETE', 'error' => $e::class.': '.$e->getMessage()]);
            throw $e;
        }
    }

    public static function rawSource(array $manifest, array $fetch, array $race): array
    {
        $relative = $fetch['raw_file_path'];
        if (! is_string($relative) || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
            throw new RuntimeException('Unsafe Raw path.');
        }

        return ['race_date' => $race['race_date'], 'absolute_path' => $manifest['raw_root'].'/'.$relative,
            'fetch_hash' => $fetch['sha256'], 'source_hash' => $fetch['sha256'],
            'fetch_bytes' => $fetch['response_size'], 'raw_response_size' => $fetch['response_size'],
            'converted_hash' => null, 'content_type' => $fetch['content_type']];
    }

    private function unresolved(array $writers, array &$year, int $y, array $row): void
    {
        $reason = $row['reason'];
        $year['unresolved_reasons'][$reason] = ($year['unresolved_reasons'][$reason] ?? 0) + 1;
        $writers['unresolved-'.$y.'.jsonl']->append($row);
    }
}
