<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\InputBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\PredictionService;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ModelIdentity;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Pipeline;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\RaceInputSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Request;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TacticalPredictionPipelineTest extends TestCase
{
    private string $directory;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->directory = sys_get_temp_dir().'/pipeline-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory.'/source');
        $this->root = Files::directory($this->directory.'/output');
        config(['tactical_prediction_pipeline.artifact_base' => $this->directory]);
        $this->schema();
        $this->fixture();
        $this->model();
        $this->app->instance(ModelIdentity::class, new class(app(ModelLoader::class), Files::identity($this->directory.'/source/model.json')['sha256']) extends ModelIdentity
        {
            public function __construct(ModelLoader $loader, private string $hash)
            {
                parent::__construct($loader);
            }

            protected function expectedHash(): string
            {
                return $this->hash;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    #[DataProvider('counts')]
    public function test_target_generation_prediction_lock_reuse_and_offline_reproduction(int $count): void
    {
        $this->restrictCount($count);
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $result = app(Pipeline::class)->execute($this->request());
        $this->assertSame('DEVELOPMENT_REPLAY_LOCKED', $result['status']);
        $path = $result['path'];
        $input = iterator_to_array(JsonlArtifact::read($path.'/input.jsonl'))[0];
        $this->assertCount($count, $input['entries']);
        $legacy = iterator_to_array((new \ReflectionMethod(InputBuilder::class, 'predictionChunk'))->invoke(app(InputBuilder::class), 2025, [10]))[0];
        foreach ($input['entries'] as $i => $entry) {
            $this->assertSame($legacy['entries'][$i], array_diff_key($entry, ['history' => true, 'history_status' => true]));
            $this->assertSame([1, 0, 0, 0], $entry['history']);
            $this->assertSame('AVAILABLE', $entry['history_status']);
        }
        $this->assertSame(Files::json($path.'/prediction.jsonl.manifest.json'), app(PredictionService::class)->run($this->directory.'/source/artifact.json', $path.'/input.jsonl', $this->directory.'/reference.jsonl'));
        foreach ($sql as $query) {
            if (str_contains($query, 'race_results')) {
                $this->assertStringContainsString('"r"."race_date" < ?', $query);
                $this->assertStringContainsString('"e"."player_id" = ?', $query);
            } elseif (str_contains($query, '"races"')) {
                $this->assertStringNotContainsString('result_status', $query);
                $this->assertStringContainsString('"r"."race_date" between ? and ?', $query);
            }
        }
        $manifest = $result['manifest'];
        $this->assertNotSame($manifest['request']['input_as_of'], $manifest['locked_at']);
        $this->assertSame('SCHEDULED_START_AT_FALLBACK', Files::json($path.'/audit.json')['cutoff_source']);
        $this->app->instance(RaceInputSource::class, new class extends RaceInputSource
        {
            public function __construct() {}

            public function capture(Request $request): array
            {
                throw new RuntimeException('DB must not be used');
            }
        });
        $this->assertSame('REUSED', app(Pipeline::class)->execute($this->request())['status']);
        $this->assertSame('REPRODUCED', app(Pipeline::class)->reproduce($this->root, 'test-01')['status']);
        $this->assertSame($manifest, app(ArtifactStore::class)->verify($path));
    }

    public static function counts(): array
    {
        return [[5], [6], [7], [8], [9]];
    }

    public function test_target_outcomes_and_same_meeting_or_future_history_do_not_affect_input_or_prediction(): void
    {
        $first = app(Pipeline::class)->execute($this->request());
        DB::table('race_results')->where('race_id', 10)->update(['rank' => 9, 'winning_technique' => 'broken', 'raw_result_text' => '{broken']);
        foreach ([[20, 10, '2025-12-29'], [21, 1, '2026-01-01'], [22, 1, '2025-12-31']] as [$id, $day, $date]) {
            DB::table('races')->insert(['id' => $id, 'race_day_id' => $day, 'race_date' => $date, 'race_type' => 'A級', 'result_status' => 'CONFIRMED']);
            DB::table('race_entries')->insert(['id' => $id * 100, 'race_id' => $id, 'player_id' => 1, 'bike_number' => 1]);
            DB::table('race_results')->insert(['id' => $id * 100, 'race_id' => $id, 'bike_number' => 1, 'raw_result_text' => '{broken']);
        }
        $second = app(Pipeline::class)->execute($this->request('test-02'));
        foreach (['input.jsonl', 'prediction.jsonl'] as $name) {
            $this->assertSame(Files::identity($first['path'].'/'.$name), Files::identity($second['path'].'/'.$name));
        }
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_sources_fail_without_publishing(string $case): void
    {
        $row = DB::table('statistic_feature_results')->where('stat_code', 'STAT-07')->first();
        match ($case) {
            'future-time' => DB::table('statistic_feature_results')->where('id', $row->id)->update(['input_as_of' => '2025-12-31T12:01:00+09:00']),
            'missing-time' => DB::table('statistic_feature_results')->where('id', $row->id)->update(['input_as_of' => null]),
            'unknown-status' => DB::table('statistic_feature_results')->where('id', $row->id)->update(['status' => 'UNKNOWN']),
            'wrong-player' => DB::table('statistic_feature_results')->where('id', $row->id)->update(['player_id' => 999]),
            'missing-entry' => DB::table('race_entries')->where('id', 101)->delete(),
            'duplicate-bike' => DB::table('race_entries')->where('id', 102)->update(['bike_number' => 1]),
            'missing-stat' => DB::table('statistic_feature_results')->where('id', $row->id)->delete(),
            'duplicate-stat' => DB::table('statistic_feature_results')->insert(array_replace((array) $row, ['id' => 10000])),
            '2026' => DB::table('races')->where('id', 10)->update(['race_date' => '2026-01-01']),
            'wrong-run' => DB::table('statistic_feature_runs')->where('id', $row->feature_run_id)->update(['run_uuid' => 'wrong']),
        };
        try {
            app(Pipeline::class)->execute($this->request());
            $this->fail('Invalid source accepted');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->root.'/requests/test-01');
        }
        $this->assertCount(1, glob($this->root.'/.staging/*/failure.json'));
    }

    public static function invalidSources(): array
    {
        return array_map(fn ($v) => [$v], ['future-time', 'missing-time', 'unknown-status', 'wrong-player', 'missing-entry', 'duplicate-bike', 'missing-stat', 'duplicate-stat', '2026', 'wrong-run']);
    }

    public function test_observed_zero_and_no_history_missing_remain_distinct(): void
    {
        DB::table('race_results')->where('race_id', 1)->where('bike_number', 1)->update(['rank' => 3, 'winning_technique' => null, 'raw_result_text' => null]);
        DB::table('race_entries')->where('id', 2)->delete();
        $input = app(RaceInputSource::class)->capture($this->request())['input'];
        $this->assertSame('AVAILABLE', $input['entries'][0]['history_status']);
        $this->assertSame([0, 0, 0, 0], $input['entries'][0]['history']);
        $this->assertSame('NO_HISTORY', $input['entries'][1]['history_status']);
        $this->assertSame([null, null, null, null], $input['entries'][1]['history']);
    }

    #[DataProvider('unavailableSignalStatuses')]
    public function test_known_unavailable_stat_status_is_preserved_as_null_by_existing_eligibility(string $status): void
    {
        DB::table('statistic_feature_results')->where('stat_code', 'STAT-07')->update(['status' => $status, 'quality_status' => 'DEGRADED']);
        $result = app(Pipeline::class)->execute($this->request());
        $this->assertSame('DEVELOPMENT_REPLAY_LOCKED', $result['status']);
        $input = iterator_to_array(JsonlArtifact::read($result['path'].'/input.jsonl'))[0];
        $legacy = iterator_to_array((new \ReflectionMethod(InputBuilder::class, 'predictionChunk'))->invoke(app(InputBuilder::class), 2025, [10]))[0];
        foreach ($input['entries'] as $i => $entry) {
            $this->assertNull($entry['signals'][0]);
            $this->assertSame($legacy['entries'][$i]['signals'], $entry['signals']);
        }
        $this->assertSame($status, Files::json($result['path'].'/audit.json')['features']['STAT-07'][0]['status']);
    }

    public static function unavailableSignalStatuses(): array
    {
        return array_map(fn ($status) => [$status->value], array_values(array_filter(StatisticFeatureResultStatus::cases(), fn ($status) => $status !== StatisticFeatureResultStatus::Valid)));
    }

    public function test_sales_close_cutoff_has_priority_and_command_executes_and_reproduces(): void
    {
        DB::table('races')->where('id', 10)->update(['sales_close_at' => '2025-12-31T11:58:00+09:00']);
        DB::table('statistic_feature_results')->update(['input_as_of' => '2025-12-31T11:58:00+09:00']);
        $this->artisan('keirin:backtest:tactical-prediction-pipeline', ['--execute' => true, '--mode' => Contract::MODE,
            '--race-id' => '10', '--input-as-of' => '2025-12-31T11:58:00+09:00', '--artifact' => $this->directory.'/source/artifact.json',
            '--request-id' => 'command', '--output-root' => $this->root])->assertSuccessful();
        $this->assertSame('SALES_CLOSE_AT', Files::json($this->root.'/requests/command/audit.json')['cutoff_source']);
        $this->artisan('keirin:backtest:tactical-prediction-pipeline', ['--reproduce' => true, '--request-id' => 'command', '--output-root' => $this->root])->assertSuccessful();
    }

    public function test_two_processes_cannot_publish_two_bundles_for_the_same_request(): void
    {
        $store = app(ArtifactStore::class);
        $store->root($this->root);
        $ready = $this->directory.'/worker-ready.json';
        $go = $this->directory.'/worker-go.json';
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Cannot fork concurrency test.');
        }
        if ($pid === 0) {
            $lock = fopen($this->root.'/.locks/test-01.lock', 'c');
            flock($lock, LOCK_EX);
            JsonlArtifact::json($ready, ['status' => 'locked']);
            $deadline = microtime(true) + 10;
            while (! is_file($go) && microtime(true) < $deadline) {
                usleep(1000);
            }
            fclose($lock);
            try {
                app(Pipeline::class)->execute($this->request());
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }
        try {
            $deadline = microtime(true) + 5;
            while (! is_file($ready) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertSame('locked', Files::json($ready)['status']);
            app(Pipeline::class)->execute($this->request());
            $this->fail('Second process bypassed lock.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BUSY', $e->getMessage());
        } finally {
            JsonlArtifact::json($go, ['proceed' => true]);
            pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame('REUSED', app(Pipeline::class)->execute($this->request())['status']);
        $this->assertCount(1, glob($this->root.'/requests/*'));
    }

    public function test_holdout_request_is_rejected_without_source_access_and_model_pin_cannot_be_bypassed(): void
    {
        try {
            new Request(Contract::MODE, 10, '2026-01-01T00:00:00+09:00', 'unused', 'bad', $this->root);
            $this->fail('2026 request accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('year', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reviewed final C1');
        (new ModelIdentity(app(ModelLoader::class)))->validate($this->directory.'/source/artifact.json');
    }

    public function test_request_conflict_busy_and_corruption_do_not_overwrite(): void
    {
        $result = app(Pipeline::class)->execute($this->request());
        $before = Files::identity($result['path'].'/manifest.json');
        $lock = fopen($this->root.'/.locks/test-01.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            app(Pipeline::class)->execute($this->request());
            $this->fail('Concurrent request accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BUSY', $e->getMessage());
        }
        fclose($lock);
        try {
            app(Pipeline::class)->execute($this->request(time: '2025-12-31T11:59:00+09:00'));
            $this->fail('Conflicting request accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CONFLICT', $e->getMessage());
        }
        $this->assertSame($before, Files::identity($result['path'].'/manifest.json'));
        $handle = fopen($result['path'].'/prediction.jsonl', 'r+b');
        fwrite($handle, '[');
        fclose($handle);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hash/size mismatch');
        app(Pipeline::class)->execute($this->request());
    }

    #[DataProvider('endDrifts')]
    public function test_end_integrity_failure_never_publishes_and_preserves_other_attempts(string $drift): void
    {
        $real = app(RaceInputSource::class);
        $root = $this->root;
        $this->app->instance(RaceInputSource::class, new class($real, $root, $drift) extends RaceInputSource
        {
            private int $calls = 0;

            public function __construct(private RaceInputSource $real, private string $root, private string $drift) {}

            public function capture(Request $request): array
            {
                $this->calls++;
                if ($this->calls === 2) {
                    if ($this->drift === 'history') {
                        DB::table('race_results')->where('race_id', 1)->update(['rank' => 3, 'winning_technique' => null, 'raw_result_text' => null]);
                    } elseif ($this->drift === 'stat') {
                        DB::table('statistic_feature_results')->where('stat_code', 'STAT-07')->update(['input_hash' => str_repeat('b', 64)]);
                    } elseif ($this->drift === 'exception') {
                        throw new RuntimeException('Synthetic interruption');
                    } else {
                        $handle = fopen(glob($this->root.'/.staging/*/'.$this->drift.'.json')[0], 'r+b');
                        fwrite($handle, '[');
                        fclose($handle);
                    }
                }

                return $this->real->capture($request);
            }
        });
        app(ArtifactStore::class)->root($root);
        mkdir($root.'/.staging/another-attempt');
        JsonlArtifact::json($root.'/.staging/another-attempt/keep.json', ['keep' => true]);
        try {
            app(Pipeline::class)->execute($this->request());
            $this->fail('End drift accepted');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($root.'/requests/test-01');
        }
        $this->assertFileExists($root.'/.staging/another-attempt/keep.json');
        $this->assertCount(1, glob($root.'/.staging/*/failure.json'));
    }

    public static function endDrifts(): array
    {
        return [['history'], ['stat'], ['exception'], ['audit'], ['model']];
    }

    public function test_command_plan_has_no_connection_and_unsupported_modes_are_rejected(): void
    {
        DB::shouldReceive('connection')->never();
        $this->artisan('keirin:backtest:tactical-prediction-pipeline', ['--plan' => true])->assertSuccessful();
        $this->expectException(RuntimeException::class);
        new Request('LIVE', 10, '2025-12-31T12:00:00+09:00', 'unused', 'test', $this->root);
    }

    private function request(string $id = 'test-01', string $time = '2025-12-31T12:00:00+09:00'): Request
    {
        return new Request(Contract::MODE, 10, $time, $this->directory.'/source/artifact.json', $id, $this->root);
    }

    private function schema(): void
    {
        foreach (['races' => ['race_day_id', 'race_number', 'entrant_count'], 'race_days' => ['race_meeting_id', 'day_number'],
            'race_meetings' => [], 'race_entries' => ['race_id', 'player_id', 'bike_number'],
            'race_results' => ['race_id', 'player_id', 'race_entry_id', 'bike_number', 'rank', 'race_result_import_id'],
            'race_result_imports' => ['race_id'], 'statistic_feature_runs' => ['target_race_count', 'processed_race_count', 'target_entry_count', 'error_count'],
            'statistic_feature_results' => ['feature_run_id', 'race_id', 'race_entry_id', 'player_id', 'bike_number']] as $table => $ints) {
            $texts = match ($table) {
                'races' => ['race_date', 'race_type', 'scheduled_start_at', 'sales_close_at', 'result_status'],
                'race_days' => ['race_date'], 'race_meetings' => ['starts_on', 'ends_on'],
                'race_entries' => ['external_player_id'],
                'race_results' => ['result_status', 'winning_technique', 'raw_result_text', 'fetched_at'],
                'race_result_imports' => ['source_hash', 'import_status'],
                'statistic_feature_runs' => ['run_uuid', 'stat_code', 'calculation_version', 'mode', 'status', 'target_from', 'target_to', 'started_at', 'finished_at'],
                default => ['stat_code', 'calculation_version', 'subject_type', 'status', 'quality_status', 'acquisition_mode', 'input_as_of', 'source_fetched_at', 'calculated_at', 'input_hash', 'features', 'evidence'],
            };
            Schema::create($table, function (Blueprint $t) use ($ints, $texts): void {
                $t->integer('id')->primary();
                foreach ($ints as $key) {
                    $t->integer($key)->nullable();
                }
                foreach ($texts as $key) {
                    $t->text($key)->nullable();
                }
            });
        }
    }

    private function fixture(): void
    {
        foreach ([1 => ['2025-12-01', '2025-12-01'], 10 => ['2025-12-29', '2025-12-31']] as $id => [$start, $date]) {
            DB::table('race_meetings')->insert(['id' => $id, 'starts_on' => $start, 'ends_on' => $date]);
            DB::table('race_days')->insert(['id' => $id, 'race_meeting_id' => $id, 'day_number' => 1, 'race_date' => $date]);
            DB::table('races')->insert(['id' => $id, 'race_day_id' => $id, 'race_date' => $date, 'race_number' => 1, 'entrant_count' => 9,
                'race_type' => 'A級', 'scheduled_start_at' => $date.'T12:00:00+09:00', 'result_status' => 'CONFIRMED']);
            DB::table('race_result_imports')->insert(['id' => $id, 'race_id' => $id, 'source_hash' => str_repeat('a', 64), 'import_status' => 'SUCCEEDED']);
            for ($bike = 1; $bike <= 9; $bike++) {
                $entry = $id === 10 ? 100 + $bike : $bike;
                DB::table('race_entries')->insert(['id' => $entry, 'race_id' => $id, 'player_id' => $bike, 'bike_number' => $bike, 'external_player_id' => (string) (900000 + $bike)]);
                DB::table('race_results')->insert(['id' => $entry, 'race_id' => $id, 'race_entry_id' => $entry, 'player_id' => $bike, 'bike_number' => $bike, 'rank' => 1,
                    'result_status' => 'FINISHED', 'winning_technique' => '逃げ', 'race_result_import_id' => $id, 'fetched_at' => '2026-01-01T00:00:00+09:00']);
            }
        }
        $runs = ['STAT-01' => app(Bt01SourceManifest::class)->forYear(2025)];
        foreach (Bt03e03Contract::STAT_CODES as $code) {
            $runs[$code] = app(Bt02SourceManifest::class)->for(2025, $code);
        }
        $id = 0;
        foreach ($runs as $code => $run) {
            $version = $code.'-existing-db-v1';
            DB::table('statistic_feature_runs')->insert(['id' => $run->featureRunId, 'run_uuid' => $run->featureRunUuid, 'stat_code' => $code,
                'calculation_version' => $version, 'mode' => 'BACKFILL', 'status' => 'PARTIALLY_SUCCEEDED', 'target_from' => $run->targetFrom, 'target_to' => $run->targetTo,
                'target_race_count' => 25273, 'processed_race_count' => 25273, 'target_entry_count' => 180005, 'error_count' => 0]);
            for ($bike = 1; $bike <= 9; $bike++) {
                $features = [];
                if ($code === 'STAT-01') {
                    $features = ['RACE_SCORE_RAW' => 100.0 + $bike, 'RACE_SCORE_AVAILABLE' => true, 'RACE_SCORE_RANK' => 10 - $bike];
                } elseif (! in_array($code, ['STAT-31', 'STAT-42'], true)) {
                    data_set($features, substr(app(Bt02SignalRegistry::class)->get($code)->primaryFeaturePath, 9), 0.0);
                }
                DB::table('statistic_feature_results')->insert(['id' => ++$id, 'feature_run_id' => $run->featureRunId, 'stat_code' => $code, 'calculation_version' => $version,
                    'subject_type' => 'RACE_ENTRY', 'race_id' => 10, 'race_entry_id' => 100 + $bike, 'player_id' => $bike, 'bike_number' => $bike,
                    'status' => 'VALID', 'quality_status' => 'FULL', 'acquisition_mode' => 'BACKFILL', 'input_as_of' => '2025-12-31T12:00:00+09:00',
                    'source_fetched_at' => null, 'calculated_at' => '2026-08-01T00:00:00+09:00', 'input_hash' => str_repeat('a', 64), 'features' => json_encode($features), 'evidence' => '{}']);
            }
        }
    }

    private function restrictCount(int $count): void
    {
        DB::table('races')->where('id', 10)->update(['entrant_count' => $count]);
        DB::table('race_entries')->where('race_id', 10)->where('bike_number', '>', $count)->delete();
        DB::table('statistic_feature_results')->where('bike_number', '>', $count)->delete();
    }

    private function model(): void
    {
        $bins = [];
        foreach (FinalContract::plan()['features'] as $code) {
            $bins[$code] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, null, null, 1)];
        }
        $layout = new Layout($bins);
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $diagnostics = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $diagnostics[$position] = ['optimizer_version' => SolverContract::OPTIMIZER_VERSION, 'status' => 'CONVERGED', 'lambda' => 1.0, 'position' => $position,
                'iteration' => 1, 'accepted_update_count' => 1, 'max_iterations' => 200, 'optimizer_attempt_count' => 1, 'eligible_race_count' => 1, 'excluded_race_count' => 0,
                'final_objective' => 1.0, 'previous_objective' => 1.0, 'relative_objective_change' => 0.0, 'maximum_coefficient_change' => 0.0,
                'current_step' => 1.0, 'prox_gradient_mapping_max' => 0.0, 'stationarity_reference_step' => 1.0, 'centering_residual_max' => 0.0];
        }
        JsonlArtifact::json($this->directory.'/source/model.json', ['experiment' => HistoryAggregator::VERSION, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'model_version' => SolverContract::MODEL_VERSION, 'objective_reference_version' => Bt03e03Contract::OPTIMIZER_VERSION, 'stat01_anchor_coefficient' => 1.0, 'lambda' => 1.0,
            'layout' => ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(), 'active_group_count_G' => count($layout->groups()),
                'numeric_edge_count' => count($layout->smoothEdges()), 'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(), 'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()],
            'position_coefficients' => array_fill_keys(Bt03e03Contract::POSITIONS, $coefficients), 'weighted_center_means' => array_fill_keys(Bt03e03Contract::POSITIONS, $layout->weightedMeans($coefficients)),
            'objectives' => array_fill_keys(Bt03e03Contract::POSITIONS, 1.0), 'iterations' => array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            'eligible_races' => array_fill_keys(Bt03e03Contract::POSITIONS, 1), 'excluded_races' => array_fill_keys(Bt03e03Contract::POSITIONS, 0), 'optimizer_diagnostics' => $diagnostics]);
        JsonlArtifact::json($this->directory.'/source/artifact.json', ['contract' => FinalContract::plan(), 'model_file' => 'model.json', 'model' => Files::identity($this->directory.'/source/model.json')]);
    }
}
