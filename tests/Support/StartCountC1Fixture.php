<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Context\Contract as Context;
use App\Domain\Keirin\Statistics\AgariC1Context\Matcher;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Contract;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Sources;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Parser;

/** Synthetic sealed metadata/counts only. No DB, Raw or outcome access. */
final class StartCountC1Fixture
{
    public static function bundle(string $root, ?callable $mutate = null, int $races = 1, int $versions = 2, int $padding = 0): Sources
    {
        foreach (['snapshots', 'c1', 'mapping'] as $dir) {
            mkdir($root.'/'.$dir, 0700);
        }
        $mapping = new Stream($root.'/mapping/mapping-audit.jsonl');
        $fetches = new Stream($root.'/snapshots/fetch-audit.jsonl');
        $sf = $cf = $expectedRaces = $expectedEntries = $provenanceYears = $coverage = [];
        $fetchId = $line = 0;
        foreach (Contract::YEARS as $year) {
            $c1 = new Stream($root.'/c1/c1-'.$year.'.jsonl');
            $snapshot = new Stream($root.'/snapshots/snapshots-'.$year.'.jsonl');
            $snapshotRows = 0;
            for ($i = $races; $i >= 1; $i--) {
                $id = ($year - 2021) * 100000 + $i;
                $race = AgariC1InputFixture::race($year, $id);
                foreach ($race['entries'] as &$entry) {
                    unset($entry['labels'], $entry['rank'], $entry['status']);
                    if ($padding > 0) {
                        $entry['signals'][0] = str_repeat('X', $padding);
                    }
                }
                unset($entry);
                if ($mutate !== null) {
                    $mutate($race, 'c1');
                }
                $c1->row($race);
                [$target, $records] = AgariC1ContextFixture::race($year, $id);
                $matches = Matcher::race($target, $records);
                foreach ($matches as $m) {
                    $row = ['year' => $year, 'race_id' => $id, 'entry_id' => $m['target']['entry_id'], 'extraction_record_line' => ++$line, ...$m];
                    if ($mutate !== null) {
                        $mutate($row, 'mapping');
                    }
                    $mapping->row($row);
                }
                for ($version = 0; $version < $versions; $version++) {
                    $fetchId++;
                    $base = ['source' => 'keirin_jp', 'fetch_log_id' => $fetchId,
                        'race' => ['race_id' => $id, 'race_date' => $year.'-08-01', 'track_code' => '22', 'race_number' => 1],
                        'fetched_at' => '2026-08-01 09:00:00+09', 'fetched_at_meaning' => 'SYSTEM_FETCH_TIME',
                        'source_parser_version' => 'synthetic', 'version' => 'STAT36-START-COUNT-v1',
                        'raw_file_path' => 'DO_NOT_READ', 'original_sha256' => str_repeat('a', 64), 'converted_sha256' => str_repeat('b', 64)];
                    $rows = [];
                    foreach ($matches as $k => $m) {
                        $row = $base + ['row_index' => $k, 'entry_id' => $m['target']['entry_id'], 'bike_number' => $k + 1,
                            'ledger_external_id' => sprintf('%06d', $k + 1), 'observed_external_id' => sprintf('%06d', $k + 1),
                            'observed_bike' => (string) ($k + 1), 'pc0201_external_id' => sprintf('%06d', $k + 1), 'identity_issues' => [],
                            'source_pointer' => 'PJ0315.sensyuTypeInfo['.$k.'].stTori',
                            'observed_race' => ['race_date' => $year.'0801', 'track_code' => '22', 'race_number' => 1],
                            'aggregation_period' => null, 'statistical_as_of' => null, 'correction_as_of' => null,
                            'timing_status' => 'UNKNOWN_NO_S_SPECIFIC_PERIOD_EVIDENCE',
                            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'points' => null];
                        self::value($row, $k === 0 ? 0 : 3);
                        if ($mutate === null || $mutate($row, 'snapshot') !== false) {
                            $rows[] = $row;
                        }
                    }
                    $missing = array_values(array_diff(range(1, 5), array_column($rows, 'bike_number')));
                    $fetch = $base + ['page' => ['observed_race' => ['race_date' => $year.'0801', 'track_code' => '22', 'race_number' => 1],
                        'issues' => [], 'summary_issues' => [], 'missing_bikes' => $missing], 'rows' => count($rows)];
                    if ($mutate !== null) {
                        $mutate($fetch, 'fetch');
                    }
                    if (($fetch['status'] ?? null) === 'FETCH_NOT_SUCCESSFUL') {
                        unset($fetch['converted_sha256'], $fetch['page']);
                        $fetch['rows'] = 0;
                        $rows = [];
                    }
                    $fetches->row($fetch);
                    foreach ($rows as $row) {
                        $snapshot->row($row);
                        $snapshotRows++;
                    }
                }
            }
            $cf['c1-'.$year.'.jsonl'] = $c1->finish();
            $sf['snapshots-'.$year.'.jsonl'] = $snapshot->finish();
            $sf['unresolved-'.$year.'.jsonl'] = Artifacts::write($root.'/snapshots', 'unresolved-'.$year.'.jsonl', []);
            $expectedRaces[$year] = $races;
            $expectedEntries[$year] = $races * 5;
            $provenanceYears[$year] = ['races' => $races, 'entries' => $races * 5, 'non_result_sha256' => $cf['c1-'.$year.'.jsonl']['sha256']];
            $coverage[$year] = ['snapshot_rows' => $snapshotRows];
        }
        $original = ['bytes' => 1, 'sha256' => str_repeat('c', 64)];
        Artifacts::publish($root.'/c1', ['contract' => ['version' => C1::VERSION, 'years' => Contract::YEARS,
            'format' => 'OUTCOME_FREE_C1_PLUS_SEPARATE_SIDECAR'], 'status' => 'INPUTS_PREPARED',
            'source' => ['c1' => '/fixed-original', 'seals' => ['/fixed-original/manifest.json' => $original],
                'expected_rows' => $expectedRaces, 'expected_targets' => $expectedEntries], 'files' => $cf]);
        $sf['fetch-audit.jsonl'] = $fetches->finish();
        $sf['coverage.json'] = Artifacts::json($root.'/snapshots', 'coverage.json', ['years' => $coverage]);
        $sf['contract.json'] = Artifacts::json($root.'/snapshots', 'contract.json', ['version' => 'STAT36-START-COUNT-v1']);
        Artifacts::publish($root.'/snapshots', ['version' => 'STAT36-START-COUNT-v1', 'kind' => 'SNAPSHOTS', 'files' => $sf]);
        $mf = ['mapping-audit.jsonl' => $mapping->finish(), 'provenance.json' => Artifacts::json($root.'/mapping', 'provenance.json',
            ['source' => ['manifest' => $original, 'years' => $provenanceYears]])];
        Artifacts::publish($root.'/mapping', ['version' => Context::VERSION, 'kind' => 'MAPPING', 'status' => 'REVIEW_PENDING',
            'historical_as_of_available' => false, 'files' => $mf]);

        return self::sources($root);
    }

    public static function sources(string $root): Sources
    {
        $pins = [];
        foreach (['snapshots', 'c1', 'mapping'] as $kind) {
            $pins[$kind] = ['path' => $root.'/'.$kind, 'seal' => Files::identity($root.'/'.$kind.'/manifest.json')];
        }

        return new Sources($pins);
    }

    public static function value(array &$row, mixed $value, bool $missing = false): void
    {
        $row['field'] = (new Parser)->field($missing ? (object) [] : (object) ['stTori' => $value]);
        $row['displayed_start_count'] = $row['identity_issues'] === [] ? $row['field']['parsed_value'] : null;
        $row['value_signature'] = hash('sha256', Files::canonical($row['field']));
    }

    public static function reseal(string $root, string $kind, string $file): Sources
    {
        $m = Files::json($root.'/'.$kind.'/manifest.json');
        $m['files'][$file] = Files::identity($root.'/'.$kind.'/'.$file);
        file_put_contents($root.'/'.$kind.'/manifest.json', Files::canonical($m)."\n");
        file_put_contents($root.'/'.$kind.'/COMPLETE.json', Files::canonical(Files::identity($root.'/'.$kind.'/manifest.json'))."\n");

        return self::sources($root);
    }
}
