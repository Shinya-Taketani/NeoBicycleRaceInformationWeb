<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Keirin\BuildAcquiredHistoryFeaturesCommand;
use App\Domain\Keirin\Statistics\Calculators\SimilarConditionsCalculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\SimilarityConditionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat01BuildOptionsDto;
use App\Domain\Keirin\Statistics\Enums\Batch02Stat;
use App\Domain\Keirin\Statistics\Repositories\HistoricalRaceRepository;
use App\Domain\Keirin\Statistics\Repositories\SimilarityConditionsRepository;
use App\Domain\Keirin\Statistics\Services\AcquiredHistoryFeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Stat01FeatureBuildService;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\HistoricalResultStateNormalizer;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\TestCase;
use Throwable;

final class AcquiredHistoryFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime'] as $directory) {
                if (! mkdir($root.'/'.$directory, 0700)) {
                    throw new RuntimeException('Cannot create isolated history test directory.');
                }
            }
            $env = fopen($root.'/.env', 'xb');
            $text = "# Isolated acquired-history test. No application secrets.\n";
            if ($env === false || fwrite($env, $text) !== strlen($text)) {
                throw new RuntimeException('Cannot create artificial environment.');
            }
            fclose($env);
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null, 'logging.default' => 'null']);
            $app->bind(Factory::class, fn () => throw new RuntimeException('HTTP forbidden.'));

            return $app;
        } catch (Throwable $error) {
            $this->temporary->retire($this->temporary->path(), 'TEST_FAILURE');
            $this->temporary = null;
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        $reason = $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE';
        try {
            parent::tearDown();
        } finally {
            $this->temporary?->retire($this->temporary->path(), $reason);
        }
    }

    public function test_new_statistics_save_read_and_reproduce_without_changing_batch02_contract(): void
    {
        $options = $this->fixture();
        $before = $this->businessHash();
        foreach (['STAT-25', 'STAT-09', 'STAT-44', 'STAT-16', 'STAT-27'] as $stat) {
            $runs = [];
            for ($i = 0; $i < 2; $i++) {
                $this->artisan('keirin:statistics:build-acquired-history', $options + ['--stat' => $stat])->assertExitCode(0);
                $runs[] = DB::table('statistic_feature_runs')->max('id');
            }
            $a = $this->semantics((int) $runs[0]);
            $this->assertCount(5, $a);
            $this->assertSame($a, $this->semantics((int) $runs[1]));
            foreach ($runs as $id) {
                $this->assertRunCounts((int) $id, 1, 5, 0);
                $item = DB::table('statistic_feature_run_items')->where('feature_run_id', $id)->sole();
                $this->assertSame(5, $item->feature_result_count);
                $this->assertSame(1, $item->attempt_count);
                $this->assertNotNull($item->started_at);
                $this->assertNotNull($item->finished_at);
            }
        }
        $dq = StatisticFeatureResult::query()->where('stat_code', 'STAT-25')->where('bike_number', 1)->firstOrFail();
        $this->assertSame(1, $dq->features['ACQUIRED_HISTORY']['disqualification_count']);
        $this->assertSame('NOT_AUTHORIZED', $dq->features['prediction_use']);
        $this->assertNull($dq->raw_points);
        $this->assertNull($dq->confidence);
        $this->assertSame(['STAT-10', 'STAT-11', 'STAT-12', 'STAT-24', 'STAT-26'], array_map(fn ($s) => $s->value, Batch02Stat::cases()));
        $this->assertSame($before, $this->businessHash());
        $experience = StatisticFeatureResult::query()->where('stat_code', 'STAT-27')->where('bike_number', 1)->firstOrFail();
        $performance = StatisticFeatureResult::query()->where('stat_code', 'STAT-16')->where('bike_number', 1)->firstOrFail();
        $this->assertEqualsWithDelta(1.0, $experience->features['weighted_experience'], 1e-12);
        $this->assertNull($performance->features['mean_rank']);
        $this->assertSame(6, $experience->evidence['history_references'][0]['comparison']['comparable_conditions']);
    }

    public function test_dry_run_emits_no_database_writes(): void
    {
        $options = $this->fixture();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        foreach (['STAT-25', 'STAT-09', 'STAT-44', 'STAT-16', 'STAT-27'] as $stat) {
            $this->artisan('keirin:statistics:build-acquired-history', $options + ['--stat' => $stat, '--dry-run' => true])
                ->expectsOutputToContain('"processed_races":1,"results":5')->assertExitCode(0);
        }
        $this->assertSame([], $writes);
    }

    public function test_artificial_storage_failure_rolls_back_entire_race_and_marks_run_failed(): void
    {
        $options = $this->fixture();
        DB::unprepared("CREATE TRIGGER artificial_fail_stat25 BEFORE INSERT ON statistic_feature_results WHEN NEW.stat_code='STAT-25' AND NEW.bike_number=2 BEGIN SELECT RAISE(ABORT, 'ARTIFICIAL_STORAGE_FAILURE'); END");
        $this->artisan('keirin:statistics:build-acquired-history', $options)->assertExitCode(1);
        $this->assertSame(0, StatisticFeatureResult::query()->where('stat_code', 'STAT-25')->count());
        $run = DB::table('statistic_feature_runs')->where('stat_code', 'STAT-25')->first();
        $this->recordReview('first-race-failure', $this->runEvidence((int) $run->id));
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(1, $run->error_count);
        $this->assertRunCounts((int) $run->id, 1, 0, 1);
        $this->assertFailedItem((int) $run->id, (int) $options['--race-id'], 'ARTIFICIAL_STORAGE_FAILURE');
    }

    public function test_second_race_failure_keeps_first_commit_and_records_exact_failure_once(): void
    {
        $options = $this->fixture(twoTargets: true);
        $races = DB::table('races')->whereBetween('race_date', ['2025-01-04', '2025-01-05'])->orderBy('id')->pluck('id')->all();
        $second = (int) $races[1];
        $inserted = [];
        StatisticFeatureResult::created(function (StatisticFeatureResult $result) use ($second, &$inserted): void {
            if ($result->stat_code === 'STAT-25' && $result->race_id === $second) {
                $inserted[] = $result->bike_number;
            }
        });
        DB::unprepared("CREATE TRIGGER artificial_fail_second BEFORE INSERT ON statistic_feature_results WHEN NEW.stat_code='STAT-25' AND NEW.race_id={$second} AND NEW.bike_number=2 BEGIN SELECT RAISE(ABORT, 'ARTIFICIAL_SECOND_RACE_FAILURE'); END");
        $this->artisan('keirin:statistics:build-acquired-history', $options)->assertExitCode(1);
        $run = DB::table('statistic_feature_runs')->where('stat_code', 'STAT-25')->sole();
        $this->recordReview('second-race-failure', $this->runEvidence((int) $run->id) + ['inserted_before_rollback' => $inserted, 'expected_processed' => 2, 'expected_saved_results' => 5]);
        $this->assertSame([1], $inserted);
        $this->assertSame(5, StatisticFeatureResult::query()->where('feature_run_id', $run->id)->where('race_id', $races[0])->count());
        $this->assertSame(0, StatisticFeatureResult::query()->where('feature_run_id', $run->id)->where('race_id', $second)->count());
        $this->assertSame('PARTIALLY_SUCCEEDED', $run->status);
        $this->assertRunCounts((int) $run->id, 2, 5, 1);
        $this->assertSame(2, DB::table('statistic_feature_run_items')->where('feature_run_id', $run->id)->count());
        $this->assertSame(5, DB::table('statistic_feature_run_items')->where('feature_run_id', $run->id)->where('race_id', $races[0])->value('feature_result_count'));
        $this->assertFailedItem((int) $run->id, $second, 'ARTIFICIAL_SECOND_RACE_FAILURE');
    }

    public function test_calculation_failure_is_recorded_before_any_result_is_saved(): void
    {
        $options = $this->fixture();
        DB::table('players')->update(['birth_date' => '2025-01-06']); // Artificial invalid input, never production data.
        $this->artisan('keirin:statistics:build-acquired-history', $options + ['--stat' => 'STAT-44'])->assertExitCode(1);
        $run = DB::table('statistic_feature_runs')->where('stat_code', 'STAT-44')->sole();
        $this->assertSame('FAILED', $run->status);
        $this->assertRunCounts((int) $run->id, 1, 0, 1);
        $this->assertFailedItem((int) $run->id, (int) $options['--race-id'], 'Birth date must precede');
    }

    public function test_history_failure_before_a_target_does_not_invent_a_race_attempt(): void
    {
        $options = $this->fixture();
        $repository = Mockery::mock(HistoricalRaceRepository::class, [app(HistoricalResultStateNormalizer::class), app(DeterministicJsonHasher::class)])->makePartial();
        $repository->shouldReceive('raceInputs')->once()->andReturnUsing(function (): \Generator {
            throw new RuntimeException('ARTIFICIAL_HISTORY_FAILURE_BEFORE_TARGET');
            yield;
        });
        app()->instance(HistoricalRaceRepository::class, $repository);
        $this->artisan('keirin:statistics:build-acquired-history', $options)->assertExitCode(1);
        $run = DB::table('statistic_feature_runs')->where('stat_code', 'STAT-25')->sole();
        $this->recordReview('history-before-target-failure', $this->runEvidence((int) $run->id));
        $this->assertSame('FAILED', $run->status);
        $this->assertRunCounts((int) $run->id, 0, 0, 1);
        $this->assertSame(0, DB::table('statistic_feature_run_items')->where('feature_run_id', $run->id)->count());
        $error = json_decode($run->error_summary, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $error);
        $this->assertNull($error[0]['race_id']);
        $this->assertSame('ARTIFICIAL_HISTORY_FAILURE_BEFORE_TARGET', $error[0]['error_message']);
    }

    public function test_command_parses_date_only_arguments_as_jst_independently_of_default_zone(): void
    {
        $before = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $method = new \ReflectionMethod(BuildAcquiredHistoryFeaturesCommand::class, 'date');
            $date = $method->invoke(new BuildAcquiredHistoryFeaturesCommand, '2024-01-01');
            $this->assertSame('2024-01-01T00:00:00+09:00', $date->format(DATE_ATOM));
        } finally {
            date_default_timezone_set($before);
        }
    }

    public function test_service_normalizes_equivalent_scope_dates_before_queries_and_saving(): void
    {
        $args = $this->fixture();
        $results = [];
        foreach (['Asia/Tokyo', 'UTC'] as $name) {
            $zone = new \DateTimeZone($name);
            $date = (new \DateTimeImmutable('2025-01-04T00:00:00+09:00'))->setTimezone($zone);
            $history = (new \DateTimeImmutable('2024-01-01T00:00:00+09:00'))->setTimezone($zone);
            $out = app(AcquiredHistoryFeatureBuildService::class)->build(new Batch02BuildOptionsDto(
                $args['--stat01-run-id'], $history, $date, $date, null, 10, false));
            $results[] = $this->semantics((int) $out['run_id']);
            $run = StatisticFeatureRun::query()->findOrFail($out['run_id']);
            $this->assertSame('2024-01-01', $run->history_from->format('Y-m-d'));
            $this->assertSame('2025-01-04', $run->target_from->format('Y-m-d'));
            $this->assertSame('2025-01-04', $run->target_to->format('Y-m-d'));
            $this->assertSame('2024-01-01', $run->parameters['history_from']);
            $this->assertSame('2025-01-04', $run->parameters['from']);
            $this->assertRunCounts((int) $out['run_id'], 1, 5, 0);
        }
        $this->assertSame($results[0], $results[1]);
    }

    public function test_forbidden_or_invalid_options_do_not_create_new_runs(): void
    {
        $options = $this->fixture();
        foreach ([['--history-from' => '2026-01-01'], ['--chunk' => '0'], ['--stat' => 'STAT-UNKNOWN'], ['--from' => '2025-01-01']] as $bad) {
            $this->artisan('keirin:statistics:build-acquired-history', array_replace($options, $bad))->assertExitCode(1);
        }
        $this->assertSame(1, DB::table('statistic_feature_runs')->count());
    }

    public function test_school_command_seals_projection_without_inventing_cohort_or_overwriting_files(): void
    {
        $raw = dirname(__DIR__).'/Fixtures/Keirin/actual/player_detail_014934.html';
        // The HTML is saved real material; this log metadata is an artificial command boundary.
        $metadata = ['bytes' => filesize($raw), 'sha256' => hash_file('sha256', $raw), 'fetch_log_id' => 1,
            'parser_version' => 'SYNTHETIC_LOG_BOUNDARY', 'fetched_at' => '2026-07-18T14:33:59+09:00',
            'source_url' => 'https://keirin.jp/pc/racerprofile', 'external_player_id' => '014934', 'content_type' => 'text/html;charset=UTF-8'];
        $source = $this->temporary->path().'/source.json';
        $handle = fopen($source, 'xb');
        $this->assertIsResource($handle);
        $text = json_encode($metadata, JSON_THROW_ON_ERROR);
        $this->assertSame(strlen($text), fwrite($handle, $text));
        fclose($handle);
        $output = $this->temporary->path().'/school.json';
        $args = ['--raw' => $raw, '--source' => $source, '--source-sha256' => hash_file('sha256', $source), '--output' => $output];
        $this->artisan('keirin:statistics:extract-school-profile', $args + ['--dry-run' => true])->assertExitCode(0);
        $this->assertFileDoesNotExist($output);
        $this->artisan('keirin:statistics:extract-school-profile', $args)->assertExitCode(0);
        $out = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(13, $out['STAT-02']['school_rank_raw']);
        $this->assertSame(11.48, $out['STAT-03']['school_200m_seconds']);
        $this->assertNull($out['STAT-02']['official_cohort_size']);
        $this->assertNull($out['STAT-03']['cohort_percentile']);
        $this->assertSame(0600, fileperms($output) & 0777);
        $seal = hash_file('sha256', $output);
        $this->artisan('keirin:statistics:extract-school-profile', $args)->assertExitCode(1);
        $this->artisan('keirin:statistics:extract-school-profile', array_replace($args, ['--source-sha256' => str_repeat('0', 64)]))->assertExitCode(1);
        $this->assertSame($seal, hash_file('sha256', $output));
    }

    public function test_similarity_uses_saved_entry_grade_and_nulls_unverified_conditions(): void
    {
        $options = $this->fixture();
        $this->artisan('keirin:statistics:build-acquired-history', $options + ['--stat' => 'STAT-27'])->assertExitCode(0);
        $before = $this->semantics((int) DB::table('statistic_feature_runs')->max('id'));
        DB::table('players')->update(['current_grade' => 'SS']); // Artificial later profile, not historical evidence.
        $this->artisan('keirin:statistics:build-acquired-history', $options + ['--stat' => 'STAT-27'])->assertExitCode(0);
        $this->assertSame($before, $this->semantics((int) DB::table('statistic_feature_runs')->max('id')));
        DB::table('races')->where('id', $options['--race-id'])->update(['entrant_count' => 6, 'race_type' => 'ARTIFICIAL_UNKNOWN']);
        DB::table('race_days')->update(['day_number' => 0]);
        DB::table('race_meetings')->update(['grade' => 'ARTIFICIAL_UNKNOWN']);
        $this->artisan('keirin:statistics:build-acquired-history', $options + ['--stat' => 'STAT-27'])->assertExitCode(0);
        $result = StatisticFeatureResult::query()->where('feature_run_id', DB::table('statistic_feature_runs')->max('id'))->firstOrFail();
        $values = $result->evidence['target_conditions']['values'];
        $this->assertNull($values['field_size']);
        $this->assertNull($values['race_stage']);
        $this->assertNull($values['meeting_grade']);
        $this->assertNull($values['day_number']);
        $this->assertSame('A1', $values['entry_grade']);
        $this->assertEqualsWithDelta(2 / 6, $result->features['mean_six_condition_coverage'], 1e-12);
    }

    private function semantics(int $id): array
    {
        return StatisticFeatureResult::query()->where('feature_run_id', $id)->orderBy('bike_number')->get()->map(fn ($r) => [
            $r->subject_key, $r->status, $r->features, $r->input_hash])->all();
    }

    public function test_similarity_repository_raw_datetime_spellings_are_hash_equivalent_without_mutating_evidence(): void
    {
        $args = $this->fixture();
        $options = new Batch02BuildOptionsDto($args['--stat01-run-id'], new \DateTimeImmutable('2024-01-01T00:00:00+09:00'), null, null, $args['--race-id'], 10, false);
        $race = app(HistoricalRaceRepository::class)->raceInputs($options)->current();
        $target = $race->entries[0];
        $history = $race->historiesByPlayer[$target->playerId];
        $repo = app(SimilarityConditionsRepository::class);
        $calc = app(SimilarConditionsCalculator::class);
        $historyEntry = $history[0]->raceEntryId;
        foreach ([$target->raceEntryId, $historyEntry] as $entry) {
            foreach (['scheduled_start_at', 'fetched_at'] as $field) {
                $table = $field === 'scheduled_start_at' ? 'races' : 'race_entries';
                $id = $field === 'scheduled_start_at' ? ($entry === $target->raceEntryId ? $target->raceId : $history[0]->raceId) : $entry;
                $date = $entry === $target->raceEntryId ? '2025-01-04' : '2024-12-01';
                $jst = $date.'T12:00:00.123456+09:00';
                $utc = $date.'T03:00:00.123456Z';
                DB::table($table)->where('id', $id)->update([$field => $jst]);
                $a = $repo->forTarget($target, $history);
                $outA = $calc->calculate($target, $history, $options, $a);
                DB::table($table)->where('id', $id)->update([$field => $utc]);
                $b = $repo->forTarget($target, $history);
                $outB = $calc->calculate($target, $history, $options, $b);
                foreach (['STAT-16', 'STAT-27'] as $stat) {
                    $this->assertSame($outA[$stat]->features, $outB[$stat]->features);
                    $this->assertSame($outA[$stat]->status, $outB[$stat]->status);
                    $this->assertSame($outA[$stat]->qualityStatus, $outB[$stat]->qualityStatus);
                    $this->assertSame($outA[$stat]->inputHash, $outB[$stat]->inputHash);
                    $evidence = $entry === $target->raceEntryId ? $outB[$stat]->evidence['target_conditions'] : $outB[$stat]->evidence['history_references'][0]['conditions'];
                    $this->assertSame($utc, $evidence[$field === 'scheduled_start_at' ? 'raw' : 'sources'][$field]);
                }
                $this->assertNotSame(get_object_vars($a[$entry]), get_object_vars($b[$entry]));
                foreach (['.123457', '.999999'] as $fraction) {
                    DB::table($table)->where('id', $id)->update([$field => $date.'T03:00:00'.$fraction.'Z']);
                    $out = $calc->calculate($target, $history, $options, $repo->forTarget($target, $history));
                    $this->assertNotSame($outB['STAT-16']->inputHash, $out['STAT-16']->inputHash);
                }
                DB::table($table)->where('id', $id)->update([$field => $date.'T03:00:01.123456Z']);
                $out = $calc->calculate($target, $history, $options, $repo->forTarget($target, $history));
                $this->assertNotSame($outB['STAT-27']->inputHash, $out['STAT-27']->inputHash);
                DB::table($table)->where('id', $id)->update([$field => $jst]);
            }
        }
        $conditions = $repo->forTarget($target, $history);
        $before = $calc->calculate($target, $history, $options, $conditions);
        $original = $conditions[$target->raceEntryId];
        $raw = $original->raw;
        $raw['entry_grade'] = 'S1';
        $conditions[$target->raceEntryId] = new SimilarityConditionsDto($original->values, $raw, $original->sources, $original->reasons);
        $after = $calc->calculate($target, $history, $options, $conditions);
        $this->assertNotSame($before['STAT-16']->inputHash, $after['STAT-16']->inputHash);
        $this->assertSame($before['STAT-16']->features, $after['STAT-16']->features);
        $sources = $original->sources;
        $sources['projection_version'] = 'SYNTHETIC_NEW_SOURCE_VERSION';
        $conditions[$target->raceEntryId] = new SimilarityConditionsDto($original->values, $original->raw, $sources, $original->reasons);
        $after = $calc->calculate($target, $history, $options, $conditions);
        $this->assertNotSame($before['STAT-27']->inputHash, $after['STAT-27']->inputHash);
        $this->assertSame($before['STAT-27']->features, $after['STAT-27']->features);
    }

    private function businessHash(): string
    {
        return hash('sha256', json_encode([DB::table('races')->orderBy('id')->get(), DB::table('race_entries')->orderBy('id')->get(),
            DB::table('race_results')->orderBy('id')->get()], JSON_THROW_ON_ERROR));
    }

    private function assertRunCounts(int $id, int $processed, int $saved, int $errors): void
    {
        $run = DB::table('statistic_feature_runs')->where('id', $id)->sole();
        $statuses = StatisticFeatureResult::query()->where('feature_run_id', $id)->select('status', DB::raw('count(*) AS n'))->groupBy('status')->pluck('n', 'status');
        $this->assertSame($processed, $run->processed_race_count);
        $this->assertSame($saved, array_sum($statuses->all()));
        $this->assertSame($statuses['VALID'] ?? 0, $run->success_count);
        $this->assertSame(($statuses['PARTIAL'] ?? 0) + ($statuses['PARTIAL_HISTORY'] ?? 0) + ($statuses['NO_HISTORY'] ?? 0), $run->partial_count);
        $this->assertSame($statuses['MISSING_INPUT'] ?? 0, $run->missing_count);
        $this->assertSame($statuses['INVALID_INPUT'] ?? 0, $run->invalid_count);
        $this->assertSame($errors, $run->error_count);
        $this->assertNotNull($run->finished_at);
    }

    private function assertFailedItem(int $runId, int $raceId, string $cause): void
    {
        $item = DB::table('statistic_feature_run_items')->where('feature_run_id', $runId)->where('race_id', $raceId)->sole();
        $this->assertSame('FAILED', $item->status);
        $this->assertSame(0, $item->feature_result_count);
        $this->assertSame(1, $item->attempt_count);
        $this->assertNotNull($item->started_at);
        $this->assertNotNull($item->finished_at);
        $this->assertNotNull($item->error_type);
        $this->assertStringContainsString($cause, $item->error_message);
        $errors = json_decode(DB::table('statistic_feature_runs')->where('id', $runId)->value('error_summary'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $errors);
        $this->assertSame($raceId, $errors[0]['race_id']);
        $this->assertSame($item->error_type, $errors[0]['error_type']);
        $this->assertSame($item->error_message, $errors[0]['error_message']);
    }

    private function runEvidence(int $id): array
    {
        return ['run' => DB::table('statistic_feature_runs')->where('id', $id)->sole(),
            'items' => DB::table('statistic_feature_run_items')->where('feature_run_id', $id)->orderBy('race_id')->get(),
            'result_counts_by_race' => DB::table('statistic_feature_results')->where('feature_run_id', $id)->select('race_id', DB::raw('count(*) as rows'))->groupBy('race_id')->get()];
    }

    private function recordReview(string $name, array $data): void
    {
        $directory = getenv('PR127_TEST_REPORT_DIRECTORY');
        if ($directory === false) {
            return;
        }
        $file = fopen($directory.'/service-'.$name.'.json', 'xb');
        $text = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        if ($file === false || fwrite($file, $text) !== strlen($text)) {
            throw new RuntimeException('Could not retain run consistency evidence.');
        }
        fclose($file);
    }

    private function fixture(bool $twoTargets = false): array
    {
        // Synthetic arithmetic/transaction controls, separate from the real snapshot acceptance.
        $track = DB::table('racetracks')->insertGetId(['source' => 'synthetic', 'external_track_id' => '01', 'name' => 'Synthetic']);
        $players = [];
        for ($bike = 1; $bike <= 5; $bike++) {
            $players[$bike] = DB::table('players')->insertGetId(['source' => 'synthetic', 'external_player_id' => sprintf('%06d', $bike),
                'name' => 'Synthetic '.$bike, 'birth_date' => '1990-01-01']);
        }
        foreach ($twoTargets ? ['2024-12-01', '2025-01-04', '2025-01-05'] : ['2024-12-01', '2025-01-04'] as $index => $date) {
            $meeting = DB::table('race_meetings')->insertGetId(['source' => 'synthetic', 'external_meeting_id' => 'synthetic-'.$index,
                'racetrack_id' => $track, 'starts_on' => $date, 'ends_on' => $date, 'duration_days' => 1, 'grade' => 'F1']);
            $day = DB::table('race_days')->insertGetId(['race_meeting_id' => $meeting, 'external_race_day_id' => 'synthetic-'.$index,
                'race_date' => $date, 'day_number' => 1]);
            $race = DB::table('races')->insertGetId(['source' => 'synthetic', 'external_race_id' => 'synthetic-'.$index,
                'racetrack_id' => $track, 'race_day_id' => $day, 'race_date' => $date, 'race_number' => 1, 'scheduled_start_at' => $date.' 12:00:00',
                'sales_close_at' => $date.' 11:55:00', 'race_type' => 'Ａ級予選', 'entrant_count' => 5,
                'result_status' => $index === 0 ? 'CONFIRMED' : 'UNAVAILABLE']);
            foreach ($players as $bike => $player) {
                DB::table('race_entries')->insert(['race_id' => $race, 'player_id' => $player, 'external_player_id' => sprintf('%06d', $bike),
                    'bike_number' => $bike, 'grade' => 'A1', 'race_score' => (string) (90 - $bike), 'fetched_at' => '2026-07-01 12:00:00']);
                if ($index === 0) {
                    DB::table('race_results')->insert(['race_id' => $race, 'bike_number' => $bike,
                        'rank' => $bike === 1 ? null : $bike - 1, 'result_status' => $bike === 1 ? 'DISQUALIFIED' : 'FINISHED',
                        'fetched_at' => '2026-07-01 12:00:00']);
                }
            }
        }
        $source = app(Stat01FeatureBuildService::class)->build($twoTargets
            ? new Stat01BuildOptionsDto(new \DateTimeImmutable('2025-01-04'), new \DateTimeImmutable('2025-01-05'), null, 10, false)
            : new Stat01BuildOptionsDto(null, null, $race, 10, false));

        return ['--stat01-run-id' => $source->runId, '--history-from' => '2024-01-01']
            + ($twoTargets ? ['--from' => '2025-01-04', '--to' => '2025-01-05'] : ['--race-id' => $race]);
    }
}
