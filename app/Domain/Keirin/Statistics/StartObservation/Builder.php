<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartObservation;

use App\Console\Commands\Keirin\ObserveStat36Command;
use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract as Audit;
use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\LineWriter;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use RuntimeException;
use Throwable;

final class Builder
{
    public function __construct(private readonly Ledger $ledger, private readonly RawReader $raw, private readonly Parser $parser) {}

    public function build(string $sourcePath, string $output, ?string $original = null): array
    {
        $parent = realpath(dirname($output));
        if ($parent === false || $output !== $parent.'/'.basename($output) || $sourcePath === $output
            || str_starts_with($output.'/', $sourcePath.'/') || str_starts_with($sourcePath.'/', $output.'/')
            || ($original !== null && ($original === $output || str_starts_with($output.'/', $original.'/')))) {
            throw new RuntimeException('Invalid or overlapping output.');
        }
        $source = $this->ledger->open($sourcePath);
        $code = $this->code();
        $prior = $original === null ? null : $this->verify($original);
        if ($prior !== null) {
            Files::same($prior['source'], $source, 'reproduction source');
            Files::same($prior['code'], $code, 'reproduction code');
        }
        Artifacts::create($output);
        try {
            $summary = $this->generate($source, $output);
            $files = $summary['files'];
            $this->ledger->verify($source);
            Files::same($code, $this->code(), 'processing code START/END');
            $files['contract.json'] = Artifacts::json($output, 'contract.json', Contract::plan());
            $files['source-manifest.json'] = Artifacts::json($output, 'source-manifest.json', ['ledger' => $source,
                'raw_references' => $files['source-references.jsonl'], 'processing_code' => $code,
                'policy' => 'HASH_EACH_REFERENCED_RAW_WHEN_READ_ONCE_PER_PASS_NO_DB_FALLBACK']);
            $files['verification.json'] = Artifacts::json($output, 'verification.json', [
                'ledger_denominators_match' => true, 'source_code_end_unchanged' => true,
                'all_referenced_raws_hash_verified' => true, 'application_db_http_access' => 0,
                '2026_race_access' => 0, 'known_start_definition' => false,
                'status' => 'DISPLAY_OBSERVATIONS_GENERATED_START_SEMANTICS_UNCONFIRMED',
                'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'points' => null]);
            $manifest = ['version' => Contract::VERSION, 'source' => $source, 'code' => $code, 'files' => $files];
            if ($prior !== null) {
                Files::same($prior, $manifest, 'independent reproduction');
                $this->verify($original);
            }
            Artifacts::publish($output, $manifest);
            $this->verify($output);
            unlink($output.'/index.sqlite');

            return ['status' => 'DISPLAY_OBSERVATIONS_GENERATED_START_SEMANTICS_UNCONFIRMED', 'path' => $output,
                'manifest' => Files::identity($output.'/manifest.json'), 'years' => $summary['years'],
                'independent_reproduction' => $prior !== null];
        } catch (Throwable $error) {
            Artifacts::json($output, 'failure.json', ['status' => 'FAILED_NOT_COMPLETE', 'error' => $error::class.': '.$error->getMessage()]);
            throw $error;
        }
    }

    private function generate(array $source, string $output): array
    {
        $index = new Index($output.'/index.sqlite');
        $writers = [];
        $years = [];
        foreach (Contract::YEARS as $year) {
            foreach (['observations', 'unresolved'] as $kind) {
                $name = $kind.'-'.$year.'.jsonl';
                $writers[$name] = new LineWriter($output.'/'.$name);
            }
            $years[$year] = ['ledger_races' => 0, 'races_without_import' => 0, 'unique_races_with_import' => 0,
                'imports' => 0, 'observation_rows' => 0, 'unresolved_rows' => 0, 'confirmed_start_values' => 0,
                'formats' => [], 'page_statuses' => [], 'display_states' => [], 'identity_states' => [],
                'field_presence' => [], 'marker_counts_per_import' => ['ZERO' => 0, 'ONE' => 0, 'MULTIPLE' => 0, 'UNMEASURABLE' => 0]];
        }
        foreach (['source-references.jsonl', 'import-audit.jsonl'] as $name) {
            $writers[$name] = new LineWriter($output.'/'.$name);
        }
        $examples = [];
        foreach ($this->ledger->races($source) as $item) {
            $race = $item['race'];
            $year = (int) substr($race['race_date'], 0, 4);
            $y = &$years[$year];
            $y['ledger_races']++;
            $y[$item['imports'] === [] ? 'races_without_import' : 'unique_races_with_import']++;
            if ($item['imports'] === []) {
                $writers['unresolved-'.$year.'.jsonl']->append(['unit' => 'RACE', 'race_id' => $race['race_id'],
                    'race_date' => $race['race_date'], 'reason' => 'NO_IMPORT_IN_ACCEPTED_LEDGER']);
            }
            foreach ($item['imports'] as $import) {
                $index->import($import['import_id']);
                $y['imports']++;
                // RawReader checks race_date BEFORE touching the file and verifies original/converted bytes.
                $html = $this->raw->read($import);
                $page = $this->parser->parse($html, $race, $item['entries'], $import);
                $provenance = ['source' => 'keirin_jp', 'import_id' => $import['import_id'], 'race_id' => $race['race_id'],
                    'race_date' => $race['race_date'], 'track_code' => $race['track_code'], 'race_number' => $race['race_number'],
                    'raw_file_path' => $import['absolute_path'], 'original_sha256' => $import['source_hash'],
                    'converted_sha256' => hash('sha256', $html), 'fetched_at' => $import['fetched_at'],
                    'fetched_at_meaning' => 'SYSTEM_FETCH_TIME', 'official_publication_at' => null,
                    'source_parser_version' => $import['parser_version'], 'parser_version' => Contract::VERSION];
                $writers['source-references.jsonl']->append($provenance + ['ledger_raw' => $import]);
                $audit = $provenance + array_diff_key($page, ['rows' => true]);
                $audit['rows'] = count($page['rows']);
                $writers['import-audit.jsonl']->append($audit);
                $this->increment($y['formats'], $page['format']);
                $this->increment($y['page_statuses'], $page['page_status']);
                $count = $page['display_s_count'];
                $y['marker_counts_per_import'][$count === null ? 'UNMEASURABLE' : ($count === 0 ? 'ZERO' : ($count === 1 ? 'ONE' : 'MULTIPLE'))]++;
                if ($page['rows'] === []) {
                    $writers['unresolved-'.$year.'.jsonl']->append(['unit' => 'IMPORT', 'import_id' => $import['import_id'],
                        'race_id' => $race['race_id'], 'reason' => $page['page_status'], 'issues' => $page['issues']]);
                }
                foreach ($page['rows'] as $row) {
                    $y['observation_rows']++;
                    $y['unresolved_rows']++;
                    $this->increment($y['display_states'], $row['display_state']);
                    $this->increment($y['identity_states'], $row['identity_status']);
                    foreach ($row['fields'] as $field => $value) {
                        $this->increment($y['field_presence'], $field.':'.$value['presence']);
                    }
                    $semantic = [];
                    foreach ($row['fields'] as $field => $value) {
                        $semantic[$field] = ['presence' => $value['presence'], 'raw' => $value['raw']];
                    }
                    $signature = hash('sha256', Files::canonical([$row['external_player_id'], $semantic]));
                    $index->entry($year, $race['race_id'], $row, $signature);
                    $observation = $provenance + $row + ['page_status' => $page['page_status'], 'page_issues' => $page['issues'],
                        'format' => $page['format'], 'header_signature' => $page['header_signature'],
                        'interpretation_status' => 'UNKNOWN_POSITION_DEFINITION', 'initial_position_status' => 'MISSING_INITIAL_POSITION',
                        'revision_key' => $race['race_id'].':'.($row['bike_number'] ?? 'unresolved-'.$row['row_index']),
                        'display_signature' => $signature, 'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'points' => null];
                    $writers['observations-'.$year.'.jsonl']->append($observation);
                    $writers['unresolved-'.$year.'.jsonl']->append(['unit' => 'OBSERVATION', 'import_id' => $import['import_id'],
                        'race_id' => $race['race_id'], 'row_index' => $row['row_index'], 'bike_number' => $row['bike_number'],
                        'reason' => 'UNKNOWN_POSITION_DEFINITION', 'display_state' => $row['display_state'], 'issues' => $row['issues']]);
                    $key = $year.':'.$row['display_state'].':'.$row['identity_status'];
                    if (count($examples[$key] ?? []) < 2) {
                        $examples[$key][] = $observation;
                    }
                }
                $key = $year.':PAGE:'.$page['page_status'];
                if (count($examples[$key] ?? []) < 2) {
                    $examples[$key][] = $audit;
                }
                unset($html);
            }
            unset($y);
        }
        $distinct = $index->finish();
        unset($index);
        $files = [];
        foreach ($writers as $name => $writer) {
            $files[$name] = $writer->finish();
        }
        foreach ($years as $year => &$y) {
            $y += $distinct[$year];
            if (array_sum($y['page_statuses']) !== $y['imports'] || array_sum($y['display_states']) !== $y['observation_rows']
                || $y['ledger_races'] !== $y['races_without_import'] + $y['unique_races_with_import']) {
                throw new RuntimeException('Observation accounting failed.');
            }
            foreach (['formats', 'page_statuses', 'display_states', 'identity_states', 'field_presence'] as $key) {
                ksort($y[$key], SORT_STRING);
            }
        }
        unset($y);
        ksort($examples, SORT_STRING);
        $files['examples.json'] = Artifacts::json($output, 'examples.json', ['rule' => 'FIRST_TWO_PER_YEAR_STATE_IDENTITY_IN_LEDGER_AND_RAW_ROW_ORDER', 'examples' => $examples]);
        $files['coverage.json'] = Artifacts::json($output, 'coverage.json', ['units' => 'IMPORT_VERSIONS_AND_DISTINCT_MATCHED_RACE_BIKE_EXTERNAL_ID_SEPARATE',
            'years' => $years, 'status' => 'START_SEMANTICS_UNCONFIRMED_NOT_USABLE_STAT36',
            'zero_start_values_is_not_zero_start_events' => true]);
        $csv = "year,dimension,state,count\n";
        foreach ($years as $year => $y) {
            foreach ($y as $dimension => $value) {
                foreach (is_array($value) ? $value : ['TOTAL' => $value] as $state => $n) {
                    $csv .= $year.','.$dimension.','.$state.','.$n."\n";
                }
            }
        }
        $files['coverage.csv'] = Artifacts::write($output, 'coverage.csv', [$csv]);

        return ['files' => $files, 'years' => $years];
    }

    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    public function verify(string $directory): array
    {
        if (realpath($directory) !== $directory) {
            throw new RuntimeException('Noncanonical observation bundle.');
        }
        Files::verify($directory.'/manifest.json', Files::json($directory.'/COMPLETE.json'));
        $m = Files::json($directory.'/manifest.json');
        if (($m['version'] ?? null) !== Contract::VERSION) {
            throw new RuntimeException('Unknown observation version.');
        }
        foreach ($m['files'] as $name => $seal) {
            if (basename($name) !== $name || str_contains($name, '..')) {
                throw new RuntimeException('Unsafe observation path.');
            }
            Files::verify($directory.'/'.$name, $seal);
        }

        return $m;
    }

    public function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach ([ObserveStat36Command::class, Audit::class, RawReader::class, Files::class, LineWriter::class,
            Artifacts::class, JsonlArtifact::class, ResultStore::class, EmbeddedJsonExtractor::class,
            CharacterEncodingConverter::class, HtmlTextNormalizer::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        $paths[] = base_path('composer.lock');
        sort($paths, SORT_STRING);
        $code = [];
        foreach (array_unique($paths) as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }

        return $code;
    }
}
