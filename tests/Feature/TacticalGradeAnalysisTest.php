<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Aggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisService;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Grade;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\MetadataSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\OuterSources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TacticalGradeAnalysisTest extends TestCase
{
    private string $directory;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('races', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->date('race_date');
            $table->integer('entrant_count');
            $table->string('race_type')->nullable();
        });
        Schema::create('race_entries', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('race_id');
            $table->integer('bike_number');
            $table->integer('player_id')->nullable();
            $table->string('grade')->nullable();
        });
        $this->directory = Files::directory(sys_get_temp_dir().'/grade-analysis-'.bin2hex(random_bytes(8)));
        Files::directory($this->directory.'/source');
        $this->root = Files::directory($this->directory.'/output');
        config(['tactical_prediction_pipeline.artifact_base' => $this->directory]);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    #[DataProvider('grades')]
    public function test_explicit_grade_normalization_preserves_raw_and_unknown_timing(?string $raw, string $grade, string $status): void
    {
        $this->assertSame(['grade_raw' => $raw, 'normalized_grade' => $grade, 'verification_status' => $status,
            'publication_time_verified' => 'UNKNOWN'], Grade::classify($raw));
    }

    public static function grades(): array
    {
        $rows = [];
        foreach (['SS', 'S1', 'S2', 'A1', 'A2', 'A3'] as $grade) {
            $rows[] = [$grade, $grade, 'VERIFIED_RACE_ENTRY_GRADE'];
        }

        return [...$rows, [' Ｓ級 １班 ', 'S1', 'VERIFIED_RACE_ENTRY_GRADE'], ['Ａ級２班', 'A2', 'VERIFIED_RACE_ENTRY_GRADE'],
            ['S級S班', 'SS', 'VERIFIED_RACE_ENTRY_GRADE'], [null, 'UNKNOWN', 'MISSING_GRADE'], ['', 'UNKNOWN', 'MISSING_GRADE'],
            ['級班不明', 'UNKNOWN', 'UNRECOGNIZED_GRADE'], ['L級1班', 'UNKNOWN', 'UNEXPECTED_KNOWN_GRADE'],
            ['S10', 'UNKNOWN', 'UNRECOGNIZED_GRADE']];
    }

    public function test_wilson_known_example_zero_denominator_and_boundary_hits(): void
    {
        $interval = Aggregator::interval(5, 10);
        $this->assertSame(0.5, $interval['hit_rate']);
        $this->assertEqualsWithDelta(0.236593090512564, $interval['ci_lower'], 1e-14);
        $this->assertEqualsWithDelta(0.763406909487436, $interval['ci_upper'], 1e-14);
        $this->assertSame(['hit_rate' => null, 'ci_lower' => null, 'ci_upper' => null, 'status' => 'NOT_EVALUABLE'], Aggregator::interval(0, 0));
        $this->assertGreaterThan(0.2, Aggregator::interval(0, 10)['ci_upper']);
        $this->assertLessThan(0.8, Aggregator::interval(10, 10)['ci_lower']);
    }

    public function test_predicted_grade_year_entrants_and_count_weighted_pooling_not_actual_winner_grade(): void
    {
        $first = $this->row(2024, 90, 5);
        $first['context']['entries'][0]['rank'] = 2;
        $first['context']['entries'][1]['rank'] = 1;
        $this->refresh($first);
        $second = $this->row(2025, 10, 9);
        $third = $this->row(2025, 11, 7);
        // The same player can have a different race-time grade in another race.
        $third['metadata']['entries'][0] = array_replace($third['metadata']['entries'][0], Grade::classify('A1'));
        $summary = [];
        $details = iterator_to_array(app(Aggregator::class)->details([$first, $second, $third], $this->reference([$first, $second, $third]), $summary));
        $this->assertSame('S1', $details[0]['normalized_grade']);
        $this->assertSame(0, $details[0]['hits']);
        $this->assertSame('A1', $details[6]['normalized_grade']);
        $this->assertSame($details[0]['player_id'], $details[6]['player_id']);
        $this->assertSame(0.5, $this->cell($summary, 'pooled', null, 1, 'S1')['hit_rate']);
        $this->assertSame(1.0, $this->cell($summary, 'year', 2025, 1, 'A1')['hit_rate']);
        $this->assertSame(1, $this->cell($summary, 'entrants', 2025, 1, 'S1', 9)['hits']);
        $this->assertSame(0, $this->cell($summary, 'entrants', 2024, 1, 'S1', 5)['hits']);
        $this->assertSame(3, array_sum(array_column(array_filter($summary['cells'], fn ($c) => $c['dimension'] === 'pooled' && $c['position'] === 1), 'eligible')));
        // Unequal yearly sample counts: 0/1 + 2/2 => 2/3, not mean(0, 1).
        $third['metadata']['entries'][0] = array_replace($third['metadata']['entries'][0], Grade::classify('S1'));
        iterator_to_array(app(Aggregator::class)->details([$first, $second, $third], $this->reference([$first, $second, $third]), $summary));
        $this->assertSame(2 / 3, $this->cell($summary, 'pooled', null, 1, 'S1')['hit_rate']);
        foreach ($summary['cells'] as $cell) {
            $this->assertSame($cell['selected'], $cell['eligible'] + $cell['excluded']);
            $this->assertSame($cell['eligible'], $cell['hits'] + $cell['misses']);
        }
    }

    #[DataProvider('ties')]
    public function test_normal_ties_and_abnormal_status_keep_original_metric_contributions(int|string $case): void
    {
        $row = $this->row();
        if (is_int($case)) {
            foreach ([$case - 1, $case] as $i) {
                $row['context']['entries'][$i]['rank'] = $case;
                $row['context']['entries'][$i]['status'] = 'TIED';
            }
        } else {
            $row['context']['entries'][0]['rank'] = null;
            $row['context']['entries'][0]['status'] = $case;
        }
        $this->refresh($row);
        $summary = [];
        $details = iterator_to_array(app(Aggregator::class)->details([$row], $this->reference([$row]), $summary));
        foreach ([1, 2, 3] as $position) {
            $this->assertSame((int) $row['saved_contributions'][$position]['numerator'], $details[$position - 1]['hits']);
            $this->assertSame((int) $row['saved_contributions'][$position]['denominator'], $details[$position - 1]['eligible']);
        }
        $this->assertTrue($summary['all_grade_rollup_verified']);
    }

    public static function ties(): array
    {
        return [[1], [2], [3], ['DISQUALIFIED'], ['DID_NOT_START'], ['WITHDRAWN'], ['DID_NOT_FINISH'], ['CRASHED']];
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_identity_result_metadata_and_holdout_are_rejected(string $case): void
    {
        $row = $this->row();
        match ($case) {
            '2026' => $row['context']['year'] = 2026,
            'rank' => $row['context']['entries'][0]['rank'] = 0,
            'status' => $row['context']['entries'][0]['status'] = 'UNKNOWN',
            'single-tied' => $row['context']['entries'][0]['status'] = 'TIED',
            'duplicate-finished' => $row['context']['entries'][1]['rank'] = 1,
            'abnormal-rank' => $row['context']['entries'][0]['status'] = 'DISQUALIFIED',
            'entry-id' => $row['metadata']['entries'][0]['race_entry_id'] = 999,
            'bike' => $row['metadata']['entries'][0]['bike_number'] = 9,
            'player' => $row['metadata']['entries'][0]['player_id'] = 999,
            'missing-entry' => array_pop($row['metadata']['entries']),
            'duplicate-entry' => $row['metadata']['entries'][1] = $row['metadata']['entries'][0],
            'unknown-coercion' => $row['metadata']['entries'][0]['normalized_grade'] = 'A3',
            'decision' => $row['decision']['primary_position_1_bike'] = 9,
            'original-contribution' => $row['saved_contributions'][1]['numerator'] = 0.0,
        };
        $summary = [];
        $this->expectException(RuntimeException::class);
        iterator_to_array(app(Aggregator::class)->details([$row], [], $summary));
    }

    public static function invalidRows(): array
    {
        return array_map(fn ($s) => [$s], ['2026', 'rank', 'status', 'single-tied', 'duplicate-finished', 'abnormal-rank',
            'entry-id', 'bike', 'player', 'missing-entry', 'duplicate-entry', 'unknown-coercion', 'decision', 'original-contribution']);
    }

    public function test_all_grade_sum_rejects_wrong_saved_evaluation_and_duplicate_race(): void
    {
        $row = $this->row();
        $reference = $this->reference([$row]);
        $reference[2024]['candidate']['POSITION_1_ACCURACY'] = 0.0;
        $summary = [];
        $this->mustFail(fn () => iterator_to_array(app(Aggregator::class)->details([$row], $reference, $summary)), 'rollup');
        $this->mustFail(fn () => iterator_to_array(app(Aggregator::class)->details([$row, $row], $reference, $summary)), 'Duplicate');
    }

    public function test_full_service_executes_readonly_and_reproduces_with_no_database_or_originals(): void
    {
        $rows = [$this->row(2024, 90, 7), $this->row(2025, 10, 9)];
        $rows[1]['metadata']['entries'][0] = array_replace($rows[1]['metadata']['entries'][0], Grade::classify(null));
        $source = $this->fixture($rows);
        // This 2026 synthetic row must never be selected by metadata queries.
        DB::table('races')->insert(['id' => 1000, 'race_date' => '2026-01-01', 'entrant_count' => 7]);
        DB::enableQueryLog();
        $result = $this->execute();
        $this->assertSame('GRADE_ANALYSIS_LOCKED', $result['status']);
        $this->assertSame(2, $result['races']);
        $this->assertSame(16, $result['entries']);
        $this->assertSame(2, $result['grade_coverage'][2025]['MISSING_GRADE']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            if (str_contains($query['query'], '"races"')) {
                $this->assertStringContainsString('between', $query['query']);
                $this->assertStringContainsString(' in ', $query['query']);
                $this->assertStringNotContainsString('*', $query['query']);
                $this->assertStringNotContainsString('result', $query['query']);
                $this->assertNotContains('2026-01-01', $query['bindings']);
            }
        }
        $this->assertSame(0, (int) DB::selectOne('PRAGMA query_only')->query_only);
        app(OuterSources::class)->verify($source);
        $manifest = app(AnalysisStore::class)->verify($result['path']);
        $this->assertSame(AnalysisStore::INVENTORY, array_keys($manifest['files']));
        $this->assertSame(6, $manifest['files']['details.jsonl']['rows']);
        config(['database.default' => 'offline-grade-test']);
        rename($this->directory.'/source', $this->directory.'/hidden-source');
        $reproduced = app(AnalysisService::class)->reproduce($this->root, 'test-01');
        $this->assertSame('REPRODUCED', $reproduced['status']);
        $this->assertSame($result['details'], $reproduced['details']);
        $this->assertSame($result['summary'], $reproduced['summary']);
        $this->assertCount(1, glob($this->root.'/events/*.json'));
        $this->artisan('keirin:backtest:tactical-grade-analysis', ['--reproduce' => true, '--output-root' => $this->root, '--analysis-id' => 'test-01'])->assertSuccessful();
    }

    public function test_plan_has_no_database_connection_or_artifact_writes(): void
    {
        config(['database.default' => 'offline-grade-test']);
        $this->artisan('keirin:backtest:tactical-grade-analysis', ['--plan' => true])->assertSuccessful();
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[DataProvider('sourceErrors')]
    public function test_original_stream_integrity_failures_never_publish(string $case): void
    {
        $row = $this->row();
        $source = $this->fixture([$row]);
        if ($case === 'source-drift') {
            $this->drift($source['years'][2024]['prediction']);
        } elseif ($case === 'db-missing') {
            DB::table('races')->delete();
        } elseif ($case === 'db-bike') {
            DB::table('race_entries')->where('id', 901)->update(['bike_number' => 9]);
        } elseif ($case === 'db-date') {
            DB::table('races')->update(['race_date' => '2026-01-01']);
        } else {
            $mock = \Mockery::mock(MetadataSource::class, [app(ReadOnlySession::class), app(ResultStore::class)])->makePartial();
            $mock->shouldReceive('verify')->once()->andThrow(new RuntimeException($case === 'end-drift' ? 'metadata START/END drift' : 'interruption'));
            $this->app->instance(MetadataSource::class, $mock);
        }
        $this->mustFail(fn () => $this->execute());
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
    }

    public static function sourceErrors(): array
    {
        return [['source-drift'], ['db-missing'], ['db-bike'], ['db-date'], ['end-drift'], ['interruption']];
    }

    #[DataProvider('generatedFiles')]
    public function test_generated_artifact_tamper_is_rejected_using_write_time_seals(string $file): void
    {
        $this->fixture([$this->row()]);
        $store = new class(app(ResultStore::class), $file) extends AnalysisStore
        {
            public function __construct(ResultStore $writer, private readonly string $file)
            {
                parent::__construct($writer);
            }

            public function publish(string $stage, string $destination, array $expected, array $summary): array
            {
                $path = $stage.'/'.$this->file;
                $text = file_get_contents($path);
                file_put_contents($path, substr_replace($text, ' ', 0, 1));

                return parent::publish($stage, $destination, $expected, $summary);
            }
        };
        $this->app->instance(AnalysisStore::class, $store);
        $this->mustFail(fn () => $this->execute(), 'hash/size');
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
        $failures = glob($this->root.'/.staging/*/failure.json');
        $this->assertCount(1, $failures);
        $this->assertSame(AnalysisStore::INVENTORY, array_keys(Files::json($failures[0])['expected_files']));
    }

    public static function generatedFiles(): array
    {
        return array_map(fn ($s) => [$s], AnalysisStore::INVENTORY);
    }

    public function test_metadata_end_reads_again_and_detects_grade_change(): void
    {
        $this->fixture([$this->row()]);
        $matched = $this->directory.'/matched.jsonl';
        $source = app(OuterSources::class)->open('synthetic');
        JsonlArtifact::write($matched, app(OuterSources::class)->rows($source));
        $stage = Files::directory($this->directory.'/capture');
        $start = app(MetadataSource::class)->capture($matched, $stage);
        DB::table('race_entries')->where('id', 901)->update(['grade' => 'S2']);
        $this->mustFail(fn () => app(MetadataSource::class)->verify($matched, $start['digest']), 'metadata START/END');
        $this->assertSame(0, (int) DB::selectOne('PRAGMA query_only')->query_only);
    }

    public function test_outer_stream_duplicate_and_missing_labels_rejected_even_with_valid_file_manifest(): void
    {
        $source = $this->fixture([$this->row()]);
        $paths = $source['years'][2024];
        foreach (['input', 'prediction', 'labels', 'contributions', 'history'] as $kind) {
            $rows = iterator_to_array(JsonlArtifact::read($paths[$kind]));
            $paths[$kind] = $this->directory.'/duplicate-'.$kind.'.jsonl';
            JsonlArtifact::write($paths[$kind], [...$rows, ...$rows]);
        }
        $source['years'][2024] = $paths;
        $this->mustFail(fn () => iterator_to_array(app(OuterSources::class)->rows($source)), 'Duplicate');
        $paths['labels'] = $this->directory.'/empty-labels.jsonl';
        JsonlArtifact::write($paths['labels'], []);
        $source['years'][2024] = $paths;
        $this->mustFail(fn () => iterator_to_array(app(OuterSources::class)->rows($source)), 'Missing source');
    }

    #[DataProvider('lateSourceFailures')]
    public function test_end_source_drift_and_interrupted_stream_keep_failure_evidence(string $case): void
    {
        $source = $this->fixture([$this->row()]);
        $this->app->instance(OuterSources::class, new class(app(Matcher::class), $source, $case) extends OuterSources
        {
            public function __construct(Matcher $matcher, private readonly array $fixture, private readonly string $case)
            {
                parent::__construct($matcher);
            }

            public function open(string $root): array
            {
                $this->verify($this->fixture);

                return $this->fixture;
            }

            public function rows(array $sources): \Generator
            {
                yield from parent::rows($sources);
                if ($this->case === 'interruption') {
                    throw new RuntimeException('Injected stream interruption.');
                }
                $path = $sources['years'][2024]['prediction'];
                $text = file_get_contents($path);
                file_put_contents($path, substr_replace($text, ' ', 0, 1));
            }
        });
        $this->mustFail(fn () => $this->execute());
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
        $this->assertCount(1, glob($this->root.'/.staging/*/failure.json'));
    }

    public static function lateSourceFailures(): array
    {
        return [['drift'], ['interruption']];
    }

    public function test_read_only_session_rejects_writes_and_restores_state_on_exception(): void
    {
        try {
            app(ReadOnlySession::class)->run(function (array $settings): void {
                $this->assertTrue($settings['query_only']);
                $this->assertSame(1, (int) DB::selectOne('PRAGMA query_only')->query_only);
                DB::table('races')->insert(['id' => 1, 'race_date' => '2024-01-01', 'entrant_count' => 7]);
            });
            $this->fail('Read-only write accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('readonly', $e->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, (int) DB::selectOne('PRAGMA query_only')->query_only);
        $this->assertSame(0, DB::table('races')->count());
    }

    public function test_unreviewed_registry_and_2026_outer_input_are_rejected_before_metadata_queries(): void
    {
        JsonlArtifact::json($this->directory.'/source/report-export-manifest.json', ['synthetic' => 'unreviewed']);
        $this->mustFail(fn () => (new OuterSources(app(Matcher::class)))->open($this->directory.'/source'), 'reviewed v2');
        $source = $this->fixture([$this->row()]);
        $original = iterator_to_array(JsonlArtifact::read($source['years'][2024]['input']));
        $original[0]['year'] = 2026;
        $path = $this->directory.'/holdout-rejection-synthetic.jsonl';
        JsonlArtifact::write($path, $original);
        $source['years'][2024]['input'] = $path;
        DB::enableQueryLog();
        $stream = app(OuterSources::class)->rows($source);
        $this->mustFail(fn () => $stream->rewind(), '2024/2025');
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    private function row(int $year = 2024, int $id = 90, int $count = 7): array
    {
        $context = ['year' => $year, 'race_id' => $id, 'entries' => []];
        $targets = $attributes = [];
        $grades = ['S1', 'S2', 'A1', 'A2', 'SS', 'A3', null, 'A1', 'S1'];
        foreach (range(1, $count) as $bike) {
            $context['entries'][] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => 80.0 - $bike, 'rank' => $bike, 'status' => 'FINISHED'];
            $targets[] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'player_id' => $bike];
            $attributes[] = ['race_entry_id' => $id * 10 + $bike, 'bike_number' => $bike, 'player_id' => $bike] + Grade::classify($grades[$bike - 1]);
        }
        $decision = ['year' => $year, 'race_id' => $id, 'primary_position_1_bike' => 1, 'primary_position_2_bike' => 2, 'primary_position_3_bike' => 3,
            'map_ordered_top3' => [1, 2, 3], 'map_top3_set' => [1, 2, 3], 'top2_marginal_bikes' => [1, 2],
            'top3_marginal_bikes' => [1, 2, 3], 'expected_ndcg_top3' => [1, 2, 3], 'winner_tie_count' => 1, 'second_third_tie_count' => 1,
            'primary_decision_tied' => false, 'primary_technical_tiebreak_used' => false];
        $row = ['context' => $context, 'decision' => $decision, 'race_date' => $year.'-01-01', 'targets' => $targets,
            'metadata' => ['year' => $year, 'race_id' => $id, 'race_date' => $year.'-01-01', 'entrant_count' => $count,
                'race_type_raw' => 'A級予選', 'stage' => 'QUALIFIER', 'source' => 'race_entries.grade / PJ0315.sensyuTypeInfo.kyuhan', 'entries' => $attributes]];
        $this->refresh($row);

        return $row;
    }

    private function refresh(array &$row): void
    {
        $value = (new Bt03e05MetricEvaluator)->raceComparison($row['context'], $row['decision']);
        foreach (Contract::METRICS as $position => $metric) {
            $row['saved_contributions'][$position] = $value['candidate'][$metric];
        }
    }

    private function reference(array $rows): array
    {
        $metrics = new Bt03e05MetricEvaluator;
        $reference = [];
        foreach (Contract::YEARS as $year) {
            $summary = $metrics->emptySummary();
            foreach ($rows as $row) {
                if ($row['context']['year'] === $year) {
                    $metrics->add($summary, $metrics->raceComparison($row['context'], $row['decision']));
                }
            }
            $reference[$year] = $summary['race_count'] > 0 ? $metrics->finish($summary)
                : ['candidate' => array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, 0.0), 'denominators' => $summary['denominators']];
        }

        return $reference;
    }

    private function fixture(array $rows): array
    {
        $source = ['files' => [], 'years' => [], 'expected_counts' => [], 'reference' => $this->reference($rows)];
        foreach ($rows as $row) {
            DB::table('races')->insert(['id' => $row['context']['race_id'], 'race_date' => $row['race_date'],
                'entrant_count' => count($row['targets']), 'race_type' => $row['metadata']['race_type_raw']]);
            foreach ($row['metadata']['entries'] as $entry) {
                DB::table('race_entries')->insert(['id' => $entry['race_entry_id'], 'race_id' => $row['context']['race_id'],
                    'bike_number' => $entry['bike_number'], 'player_id' => $entry['player_id'], 'grade' => $entry['grade_raw']]);
            }
        }
        foreach (Contract::YEARS as $year) {
            $data = array_fill_keys(['input', 'prediction', 'labels', 'contributions', 'history'], []);
            foreach ($rows as $row) {
                if ($row['context']['year'] !== $year) {
                    continue;
                }
                $input = $row['context'];
                foreach ($input['entries'] as &$entry) {
                    unset($entry['rank'], $entry['status']);
                }
                unset($entry);
                $data['input'][] = $input;
                $data['prediction'][] = ['probabilities' => $input, 'decision' => $row['decision']];
                $data['labels'][] = $row['context'];
                $data['contributions'][] = ['race_id' => $input['race_id'], 'C1-STAT01' => (new Bt03e05MetricEvaluator)->raceComparison($row['context'], $row['decision'])];
                foreach ($row['targets'] as $target) {
                    $data['history'][] = ['target' => ['race_id' => $input['race_id'], 'race_date' => $row['race_date'],
                        'entry_id' => $target['id'], 'bike' => $target['bike'], 'player_id' => $target['player_id']]];
                }
            }
            foreach ($data as $kind => $stream) {
                $path = $this->directory.'/source/'.$year.'-'.$kind.'.jsonl';
                JsonlArtifact::write($path, $stream);
                $source['years'][$year][$kind] = $path;
                $source['files'][$path] = Files::identity($path);
                $source['files'][$path.'.manifest.json'] = Files::identity($path.'.manifest.json');
            }
            $source['expected_counts'][$year] = count($data['input']);
        }
        $this->app->instance(OuterSources::class, new class(app(Matcher::class), $source) extends OuterSources
        {
            public function __construct(Matcher $matcher, private readonly array $fixture)
            {
                parent::__construct($matcher);
            }

            public function open(string $root): array
            {
                $this->verify($this->fixture);

                return $this->fixture;
            }
        });

        return $source;
    }

    private function cell(array $summary, string $dimension, ?int $year, int $position, string $grade, ?int $entrants = null): array
    {
        $cells = array_values(array_filter($summary['cells'], fn ($c) => $c['dimension'] === $dimension && $c['year'] === $year
            && $c['position'] === $position && $c['grade'] === $grade && $c['entrant_count'] === $entrants));
        $this->assertCount(1, $cells);

        return $cells[0];
    }

    private function execute(): array
    {
        return app(AnalysisService::class)->execute($this->root, 'test-01', 'synthetic');
    }

    private function mustFail(callable $work, string $message = ''): void
    {
        try {
            $work();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }
        $this->fail('Invalid operation was accepted.');
    }

    private function drift(string $path): void
    {
        $text = file_get_contents($path);
        file_put_contents($path, substr_replace($text, ' ', 0, 1));
    }
}
