<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Aggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\MetadataSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Service;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Store;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TacticalMeetingGradeAnalysisTest extends TestCase
{
    private string $directory;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('racetracks', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->string('external_track_id');
            $t->string('name');
        });
        Schema::create('race_meetings', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('racetrack_id');
            $t->string('grade')->nullable();
            $t->date('starts_on');
            $t->date('ends_on');
        });
        Schema::create('race_days', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('race_meeting_id');
            $t->date('race_date');
        });
        Schema::create('races', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('race_day_id');
            $t->integer('racetrack_id');
            $t->date('race_date');
            $t->string('grade')->nullable();
            $t->string('race_type')->nullable();
            $t->integer('entrant_count');
        });
        DB::table('racetracks')->insert(['id' => 1, 'external_track_id' => '11', 'name' => 'Synthetic']);
        $this->directory = Files::directory(sys_get_temp_dir().'/meeting-analysis-'.bin2hex(random_bytes(8)));
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

    #[DataProvider('normalizations')]
    public function test_explicit_normalization_only(?string $raw, string $expected): void
    {
        $this->assertSame($expected, Classification::normalize($raw));
    }

    public static function normalizations(): array
    {
        return [['GP', 'GP'], ['ＧＰ', 'GP'], ['GⅠ', 'G1'], ['GⅡ', 'G2'], ['GⅢ', 'G3'],
            ['FⅠ', 'F1'], ['FⅡ', 'F2'], [' G1 ', 'G1'], ['GII', 'G2'], ['GIII', 'G3'],
            ['FI', 'F1'], ['FII', 'F2'], ['S1', 'UNKNOWN'], ['GP特別', 'UNKNOWN'], [null, 'UNKNOWN'], ['', 'UNKNOWN']];
    }

    #[DataProvider('classifications')]
    public function test_same_meeting_classification_no_majority_no_rider_grade(?string $meeting, array $headers, string $expected, string $reason): void
    {
        $rows = [];
        foreach ($headers as $index => $header) {
            $r = $this->row(2024, $index + 1);
            $r['metadata']['meeting_id'] = 50;
            $r['metadata']['meeting_grade_raw'] = $meeting;
            $r['metadata']['race_grade_raw'] = $header;
            $rows[] = $r['metadata'];
        }
        $groups = app(Classification::class)->meetings($rows);
        $this->assertCount(1, $groups);
        $this->assertSame($expected, $groups[50]['grade']);
        $this->assertSame($reason, $groups[50]['reason']);
        $this->assertSame($meeting, $groups[50]['identity']['meeting_grade_raw']);
    }

    public static function classifications(): array
    {
        return [['G1', ['GⅠ', 'G1'], 'G1', 'SCHEDULE_AND_JSJ001_HEADER_AGREE'],
            [null, ['GP', 'GP'], 'GP', 'JSJ001_MEETING_HEADER_FALLBACK'],
            ['F1', ['F1', 'GP', 'F1'], 'UNKNOWN', 'CONFLICTING_OR_MIXED_GRADES'],
            [null, ['GP', 'F1'], 'UNKNOWN', 'CONFLICTING_OR_MIXED_GRADES'],
            ['F1', ['G1'], 'UNKNOWN', 'CONFLICTING_OR_MIXED_GRADES'],
            ['F2', [null], 'F2', 'SCHEDULE_ONLY_MISSING_HEADER'],
            ['UNKNOWN', ['F2'], 'UNKNOWN', 'UNRESOLVED_MEETING_GRADE'],
            [null, [null], 'UNKNOWN', 'UNRESOLVED_MEETING_GRADE']];
    }

    public function test_race_class_and_stage_do_not_use_predicted_rider(): void
    {
        $this->assertSame('A_CHALLENGE', Classification::raceClass('Ａ級チ準決'));
        $this->assertSame('SEMIFINAL', Classification::stage('Ａ級チ準決'));
        $this->assertSame('A1_A2', Classification::raceClass('Ａ級予選'));
        $this->assertSame('S_CLASS', Classification::raceClass('Ｓ級ＧＰ'));
        $this->assertSame('UNKNOWN', Classification::stage('Ｓ級ＧＰ'));
        $this->assertSame('UNKNOWN', Classification::raceClass('Ａ級男ア予'));
        $this->assertSame('FINAL', Classification::stage('Ｓ級決勝'));
    }

    #[DataProvider('tiePositions')]
    public function test_hit_at_three_has_its_own_population_and_no_wilson(int $position): void
    {
        $normal = $this->row();
        $tied = $this->row(2024, 2);
        foreach ([$position - 1, $position] as $index) {
            $tied['context']['entries'][$index]['rank'] = $position;
            $tied['context']['entries'][$index]['status'] = 'TIED';
        }
        $this->refresh($tied);
        $rows = [$normal, $tied, $this->row(2025, 3)];
        [$summary, $details] = $this->aggregate($rows);
        $cell = $this->cell($summary, 'year', 2024, 'F2');
        $this->assertSame(2, $cell['races']);
        $this->assertSame(3, $cell['metrics']['H3']['denominator']);
        $this->assertSame(3, $cell['metrics']['H3']['hits']);
        $this->assertSame(1, $cell['metrics']['H3']['excluded_races']);
        $this->assertNull($cell['metrics']['H3']['ci_lower']);
        $this->assertNull($cell['metrics']['H3']['ci_upper']);
        $this->assertStringContainsString('TIED_POSITION_'.$position, $details[1]['metrics']['H3']['exclusion_reason']);
        $this->assertSame(1, $cell['metrics']['P'.$position]['denominator']);
        $this->assertSame(3, count($details));
    }

    public static function tiePositions(): array
    {
        return [[1], [2], [3]];
    }

    public function test_unknown_meeting_distinct_counts_weighted_pooling_and_selection_independence(): void
    {
        $rows = [$this->row(2024, 1), $this->row(2025, 2), $this->row(2025, 3)];
        $rows[2]['metadata']['meeting_id'] = $rows[1]['metadata']['meeting_id'];
        foreach ($rows as &$row) {
            $row['metadata']['meeting_grade_raw'] = null;
            $row['metadata']['race_grade_raw'] = null;
        }
        unset($row);
        $rows[0]['decision']['primary_position_1_bike'] = 4;
        $this->refresh($rows[0]);
        [$summary] = $this->aggregate($rows);
        $cell = $this->cell($summary, 'pooled', 'POOLED', 'UNKNOWN');
        $this->assertSame(3, $cell['races']);
        $this->assertSame(2, $cell['meetings']);
        $this->assertSame(2 / 3, $cell['metrics']['P1']['hit_rate']);
        $this->assertTrue($summary['all_grade_rollup_verified']);
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_rows_and_original_contribution_drift_are_rejected(string $case): void
    {
        $row = $this->row();
        match ($case) {
            'year' => $row['metadata']['year'] = 2026,
            'race' => $row['metadata']['race_id'] = 0,
            'count' => $row['metadata']['entrant_count'] = 9,
            'contribution' => $row['saved']['H3']['numerator'] = 0.0,
            'decision' => $row['decision']['primary_position_1_bike'] = 9,
            'status' => $row['context']['entries'][0]['status'] = 'INVALID',
        };
        $this->expectException(RuntimeException::class);
        $this->aggregate([$row, $this->row(2025, 2)]);
    }

    public static function invalidRows(): array
    {
        return [['year'], ['race'], ['count'], ['contribution'], ['decision'], ['status']];
    }

    public function test_duplicate_race_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->aggregate([$this->row(), $this->row(), $this->row(2025, 2)]);
    }

    public function test_execution_reproduction_no_database_and_all_four_metrics_match(): void
    {
        $this->fixture([$this->row(), $this->row(2025, 2)]);
        DB::enableQueryLog();
        $result = $this->execute();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/race_results|race_entries|players|race_payouts|insert |update |delete /i', $query['query']);
            if (str_contains($query['query'], 'from "races"')) {
                $this->assertStringContainsString('"r"."id" in', $query['query']);
                $this->assertStringContainsString('"r"."race_date" between', $query['query']);
            }
        }
        $this->assertSame('MEETING_ANALYSIS_LOCKED', $result['status']);
        $this->assertSame(2, $result['races']);
        $manifest = app(Store::class)->verify($result['path']);
        $this->assertSame(Store::INVENTORY, array_keys($manifest['files']));
        $this->assertArrayHasKey('app/Domain/Keirin/Backtest/Services/Bt03e02Contract.php', Files::json($result['path'].'/code.json')['files']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $reproduced = app(Service::class)->reproduce($this->root, 'test-01');
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame('REPRODUCED', $reproduced['status']);
        $this->assertSame($result['details'], $reproduced['details']);
        $this->assertSame($result['summary'], $reproduced['summary']);
        $this->assertSame(1, count(glob($this->root.'/events/*.json')));
    }

    #[DataProvider('tamperFiles')]
    public function test_saved_tampering_is_rejected(string $file): void
    {
        $this->fixture([$this->row(), $this->row(2025, 2)]);
        $result = $this->execute();
        $path = $result['path'].'/'.$file;
        $bytes = file_get_contents($path);
        file_put_contents($path, substr_replace($bytes, '!', 5, 1));
        $this->expectException(RuntimeException::class);
        app(Service::class)->reproduce($this->root, 'test-01');
    }

    public static function tamperFiles(): array
    {
        return array_map(fn ($file) => [$file], ['metadata.jsonl', 'meetings.json', 'analysis-input.jsonl', 'details.jsonl',
            'summary.json', 'summary.csv', 'code.json', 'sources.json', 'contract.json', 'source-end.json']);
    }

    public function test_start_end_attribute_drift_prevents_publication(): void
    {
        $source = $this->fixture([$this->row(), $this->row(2025, 2)]);
        $this->app->instance(MetadataSource::class, new class(app(ReadOnlySession::class), app(ResultStore::class)) extends MetadataSource
        {
            public function verify(string $input, array $expected): array
            {
                DB::table('race_meetings')->where('id', 1)->update(['grade' => 'F1']);

                return parent::verify($input, $expected);
            }
        });
        try {
            $this->execute();
            $this->fail('Drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('START/END', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
        $this->assertCount(1, glob($this->root.'/.staging/*/failure.json'));
        app(Sources::class)->verify($source);
    }

    public function test_generation_time_seals_prevent_publication_of_corrupt_output(): void
    {
        $this->fixture([$this->row(), $this->row(2025, 2)]);
        $this->app->instance(Store::class, new class(app(ResultStore::class), app(AnalysisStore::class)) extends Store
        {
            public function publish(string $stage, string $destination, array $expected, array $summary): array
            {
                file_put_contents($stage.'/summary.csv', 'corrupt');

                return parent::publish($stage, $destination, $expected, $summary);
            }
        });
        try {
            $this->execute();
            $this->fail('Corrupt output accepted.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
        }
    }

    public function test_plan_has_no_database_queries_and_no_inference(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->artisan('keirin:backtest:tactical-meeting-grade-analysis', ['--plan' => true])->assertSuccessful();
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame('NONE', Contract::plan()['inference']);
        $this->artisan('keirin:backtest:tactical-meeting-grade-analysis', ['--execute' => true])->assertFailed();
    }

    public function test_chunk_boundary_unsorted_race_ids_and_gp_supporting_races(): void
    {
        $rows = [];
        foreach (range(105, 1) as $id) {
            $row = $this->row(2024, $id);
            $row['metadata']['meeting_id'] = 10;
            $row['metadata']['meeting_grade_raw'] = null;
            $row['metadata']['race_grade_raw'] = 'GP';
            $row['metadata']['race_type_raw'] = $id === 105 ? 'Ｓ級ＧＰ' : 'Ｓ級一般';
            $rows[] = $row;
        }
        $rows[] = $this->row(2025, 200);
        $this->fixture($rows);
        $result = $this->execute();
        $this->assertSame(106, $result['races']);
        $summary = Files::json($result['path'].'/summary.json');
        $cell = $this->cell($summary, 'year', 2024, 'GP');
        $this->assertSame(105, $cell['races']);
        $this->assertSame(1, $cell['meetings']);
        $this->assertSame(315, $cell['metrics']['H3']['denominator']);
        $this->assertSame($result['details'], app(Service::class)->reproduce($this->root, 'test-01')['details']);
    }

    public function test_missing_relation_is_unknown_not_dropped(): void
    {
        $rows = [$this->row(), $this->row(2025, 2)];
        $rows[0]['metadata']['meeting_id'] = null;
        [$summary] = $this->aggregate($rows);
        $cell = $this->cell($summary, 'year', 2024, 'UNKNOWN');
        $this->assertSame(1, $cell['races']);
        $this->assertSame(0, $cell['meetings']);
        $this->assertSame(1, $cell['metrics']['P1']['denominator']);
    }

    public function test_same_meeting_identity_change_is_rejected(): void
    {
        $a = $this->row();
        $b = $this->row(2024, 2);
        $b['metadata']['meeting_id'] = 1;
        $b['metadata']['meeting_grade_raw'] = 'F1';
        $this->expectException(RuntimeException::class);
        $this->aggregate([$a, $b]);
    }

    public function test_metadata_guard_rejects_holdout_before_target_query(): void
    {
        $source = $this->fixture([$this->row(), $this->row(2025, 2)]);
        $invalid = $this->row(2026, 3);
        $path = $this->directory.'/source/invalid-year.jsonl';
        JsonlArtifact::write($path, [['context' => $invalid['context'], 'race_date' => '2026-01-01']]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(MetadataSource::class)->capture($path, $this->root);
            $this->fail('Holdout accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('identity/year', $e->getMessage());
        }
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('from "races"', $query['query']);
        }
        DB::disableQueryLog();
        app(Sources::class)->verify($source);
    }

    public function test_source_end_drift_prevents_publication(): void
    {
        $source = $this->fixture([$this->row(), $this->row(2025, 2)]);
        $this->app->instance(MetadataSource::class, new class(app(ReadOnlySession::class), app(ResultStore::class)) extends MetadataSource
        {
            public function verify(string $input, array $expected): array
            {
                $end = parent::verify($input, $expected);
                $bytes = file_get_contents($input);
                file_put_contents($input, substr_replace($bytes, '!', 5, 1));

                return $end;
            }
        });
        try {
            $this->execute();
            $this->fail('Source drift accepted.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
        }
        $this->assertNotSame($source['files'][$source['input']], Files::identity($source['input']));
    }

    public function test_read_only_session_prevents_writes(): void
    {
        $this->expectException(QueryException::class);
        app(ReadOnlySession::class)->run(fn () => DB::table('racetracks')->delete());
    }

    private function row(int $year = 2024, int $id = 1): array
    {
        $context = ['year' => $year, 'race_id' => $id, 'entries' => []];
        foreach (range(1, 7) as $bike) {
            $context['entries'][] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => 80.0 - $bike, 'rank' => $bike, 'status' => 'FINISHED'];
        }
        $row = ['context' => $context, 'decision' => ['year' => $year, 'race_id' => $id,
            'primary_position_1_bike' => 1, 'primary_position_2_bike' => 2, 'primary_position_3_bike' => 3,
            'map_ordered_top3' => [1, 2, 3], 'map_top3_set' => [1, 2, 3], 'top2_marginal_bikes' => [1, 2],
            'top3_marginal_bikes' => [1, 2, 3], 'expected_ndcg_top3' => [1, 2, 3], 'winner_tie_count' => 1,
            'second_third_tie_count' => 1, 'primary_decision_tied' => false, 'primary_technical_tiebreak_used' => false],
            'metadata' => ['year' => $year, 'race_id' => $id, 'race_date' => $year.'-01-01', 'entrant_count' => 7,
                'meeting_id' => $id, 'meeting_grade_raw' => 'F2', 'race_grade_raw' => 'F2', 'race_type_raw' => 'Ａ級予選',
                'starts_on' => $year.'-01-01', 'ends_on' => $year.'-01-03', 'meeting_track_id' => 1, 'track_code' => '11']];
        $this->refresh($row);

        return $row;
    }

    private function refresh(array &$row): void
    {
        $values = app(Bt03e05MetricEvaluator::class)->raceComparison($row['context'], $row['decision']);
        foreach (Contract::METRICS as $key => $metric) {
            $row['saved'][$key] = $values['candidate'][$metric];
        }
    }

    private function reference(array $rows): array
    {
        $metrics = app(Bt03e05MetricEvaluator::class);
        $reference = [];
        foreach ([2024, 2025] as $year) {
            $summary = $metrics->emptySummary();
            foreach ($rows as $row) {
                if ($row['context']['year'] === $year) {
                    $metrics->add($summary, $metrics->raceComparison($row['context'], $row['decision']));
                }
            }
            $reference[$year] = $summary['race_count'] ? $metrics->finish($summary)
                : ['race_count' => 0, 'candidate' => [], 'denominators' => $summary['denominators']];
        }

        return $reference;
    }

    private function aggregate(array $rows): array
    {
        $groups = app(Classification::class)->meetings(array_column($rows, 'metadata'));
        $summary = [];
        $stream = app(Aggregator::class)->details($rows, $groups, $this->reference($rows), $summary);
        $details = iterator_to_array($stream);

        return [$summary, $details];
    }

    private function cell(array $summary, string $dimension, int|string $year, string $grade): array
    {
        $cells = array_values(array_filter($summary['cells'], fn ($c) => $c['dimension'] === $dimension && $c['year'] === $year && $c['grade'] === $grade));
        $this->assertCount(1, $cells);

        return $cells[0];
    }

    private function fixture(array $rows): array
    {
        $source = ['input' => $this->directory.'/source/old.jsonl', 'files' => [], 'years' => [],
            'expected_counts' => [], 'reference' => $this->reference($rows)];
        $old = [];
        foreach ($rows as $row) {
            $m = $row['metadata'];
            DB::table('race_meetings')->insertOrIgnore(['id' => $m['meeting_id'], 'racetrack_id' => 1,
                'grade' => $m['meeting_grade_raw'], 'starts_on' => $m['starts_on'], 'ends_on' => $m['ends_on']]);
            DB::table('race_days')->insert(['id' => $m['race_id'], 'race_meeting_id' => $m['meeting_id'], 'race_date' => $m['race_date']]);
            DB::table('races')->insert(['id' => $m['race_id'], 'race_day_id' => $m['race_id'], 'racetrack_id' => 1,
                'race_date' => $m['race_date'], 'grade' => $m['race_grade_raw'], 'race_type' => $m['race_type_raw'], 'entrant_count' => $m['entrant_count']]);
            $old[] = ['context' => $row['context'], 'decision' => $row['decision'], 'metadata' => $m,
                'race_date' => $m['race_date'], 'saved_contributions' => [1 => $row['saved']['P1'], 2 => $row['saved']['P2'], 3 => $row['saved']['P3']]];
        }
        JsonlArtifact::write($source['input'], $old);
        $paths = [$source['input']];
        foreach ([2024, 2025] as $year) {
            $data = [];
            foreach ($rows as $row) {
                if ($row['context']['year'] === $year) {
                    $data[] = ['race_id' => $row['context']['race_id'], 'C1-STAT01' => app(Bt03e05MetricEvaluator::class)->raceComparison($row['context'], $row['decision'])];
                }
            }
            $path = $this->directory.'/source/contributions-'.$year.'.jsonl';
            JsonlArtifact::write($path, $data);
            $paths[] = $path;
            $source['years'][$year]['contributions'] = $path;
            $source['expected_counts'][$year] = count($data);
        }
        foreach ($paths as $path) {
            $source['files'][$path] = Files::identity($path);
            $source['files'][$path.'.manifest.json'] = Files::identity($path.'.manifest.json');
        }
        $this->app->instance(Sources::class, new class(app(AnalysisStore::class), $source) extends Sources
        {
            public function __construct(AnalysisStore $store, private readonly array $source)
            {
                parent::__construct($store);
            }

            public function open(string $bundle): array
            {
                $this->verify($this->source);

                return $this->source;
            }
        });

        return $source;
    }

    private function execute(): array
    {
        return app(Service::class)->execute($this->root, 'test-01', 'synthetic');
    }
}
