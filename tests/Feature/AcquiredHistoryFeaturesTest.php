<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Statistics\DTO\Stat01BuildOptionsDto;
use App\Domain\Keirin\Statistics\Enums\Batch02Stat;
use App\Domain\Keirin\Statistics\Services\Stat01FeatureBuildService;
use App\Models\StatisticFeatureResult;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
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
            $this->artisan('keirin:statistics:build-acquired-history', $options + ['--stat' => $stat, '--dry-run' => true])->assertExitCode(0);
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
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(1, $run->error_count);
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

    private function businessHash(): string
    {
        return hash('sha256', json_encode([DB::table('races')->orderBy('id')->get(), DB::table('race_entries')->orderBy('id')->get(),
            DB::table('race_results')->orderBy('id')->get()], JSON_THROW_ON_ERROR));
    }

    private function fixture(): array
    {
        // Synthetic arithmetic/transaction controls, separate from the real snapshot acceptance.
        $track = DB::table('racetracks')->insertGetId(['source' => 'synthetic', 'external_track_id' => '01', 'name' => 'Synthetic']);
        $players = [];
        for ($bike = 1; $bike <= 5; $bike++) {
            $players[$bike] = DB::table('players')->insertGetId(['source' => 'synthetic', 'external_player_id' => sprintf('%06d', $bike),
                'name' => 'Synthetic '.$bike, 'birth_date' => '1990-01-01']);
        }
        foreach (['2024-12-01', '2025-01-04'] as $index => $date) {
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
        $source = app(Stat01FeatureBuildService::class)->build(new Stat01BuildOptionsDto(null, null, $race, 10, false));

        return ['--stat01-run-id' => $source->runId, '--race-id' => $race, '--history-from' => '2024-01-01'];
    }
}
