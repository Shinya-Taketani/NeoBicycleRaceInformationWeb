<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch05BuildOptionsDto;
use App\Domain\Keirin\Statistics\Services\AcquiredHistoryFeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch02FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch03FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch04FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch05FeatureBuildService;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StatCategory1Fixture as Fixture;
use Tests\TestCase;

final class BuildStat20AcquiredHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_twenty_paths_save_read_and_reproduce_maximum_nine_bike_race(): void
    {
        $args = $this->fixture();
        $this->assertCount(19, $args['--source-run-id']);
        $this->assertSame(172, StatisticFeatureResult::query()->count());
        $this->assertSame(1, StatisticFeatureResult::query()->where('stat_code', 'STAT-41')->count());
        $old = $this->rows();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->artisan('keirin:statistics:build-stat20-acquired-history', $args + ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame([], $writes);
        $this->assertSame($old, $this->rows());
        $saved = [];
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('keirin:statistics:build-stat20-acquired-history', $args)->assertExitCode(0);
            $run = StatisticFeatureRun::query()->where('stat_code', 'STAT-20')->orderByDesc('id')->firstOrFail();
            $rows = StatisticFeatureResult::query()->where('feature_run_id', $run->id)->orderBy('bike_number')->get();
            $this->assertCount(9, $rows);
            $this->assertSame(0, $run->error_count);
            $this->assertSame(9, $run->partial_count);
            $this->assertSame(9, DB::table('statistic_feature_run_items')->where('feature_run_id', $run->id)->sole()->feature_result_count);
            foreach ($rows as $r) {
                $this->assertCount(20, $r->features['STAT_QUALITY']);
                $this->assertSame(0, $r->features['SOURCE_RESULT_COVERAGE']['counts']['DATA_SOURCE_PENDING']);
                $this->assertNull($r->raw_points);
                $this->assertNull($r->confidence);
                $this->assertNull($r->effective_points);
            }
            $saved[] = $rows->map(fn ($r) => [$r->subject_key, $r->features, $r->evidence, $r->input_hash])->all();
        }
        $this->assertSame($saved[0], $saved[1]);
        $this->assertSame($old[1], StatisticFeatureResult::query()->where('stat_code', '!=', 'STAT-20')->orderBy('id')->get()->toArray());
    }

    public function test_storage_failure_rolls_back_race_and_records_exact_counts(): void
    {
        $args = $this->fixture(5);
        DB::unprepared("CREATE TRIGGER synthetic_stat20_fail BEFORE INSERT ON statistic_feature_results WHEN NEW.stat_code='STAT-20' AND NEW.bike_number=2 BEGIN SELECT RAISE(ABORT, 'SYNTHETIC_STAT20_STORAGE_FAILURE'); END");
        $this->artisan('keirin:statistics:build-stat20-acquired-history', $args)->assertExitCode(1);
        $run = StatisticFeatureRun::query()->where('stat_code', 'STAT-20')->sole();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(1, $run->error_count);
        $this->assertSame(1, $run->processed_race_count);
        $this->assertSame(0, $run->partial_count);
        $this->assertSame(0, StatisticFeatureResult::query()->where('feature_run_id', $run->id)->count());
        $item = DB::table('statistic_feature_run_items')->where('feature_run_id', $run->id)->sole();
        $this->assertSame('FAILED', $item->status);
        $this->assertSame(0, $item->feature_result_count);
        $this->assertCount(1, json_decode($run->error_summary, true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('unsupportedRuns')]
    public function test_unsupported_versions_and_old_saved_contracts_are_rejected_before_writes(array $changes): void
    {
        $args = $this->fixture(5);
        StatisticFeatureRun::query()->where('stat_code', 'STAT-09')->sole()->forceFill($changes)->save();
        $before = $this->rows();
        $this->artisan('keirin:statistics:build-stat20-acquired-history', $args)->assertExitCode(1);
        $this->assertSame($before, $this->rows());
    }

    public static function unsupportedRuns(): array
    {
        return [[['calculation_version' => 'STAT-09-future-v99']], [['parameters' => ['stat01_run_id' => 1]]],
            [['mode' => 'AS_OF']], [['status' => 'RUNNING']], [['finished_at' => null]]];
    }

    public function test_failed_upstream_is_audited_and_unselected_run_is_not_silently_removed(): void
    {
        $args = $this->fixture(5);
        $upstream = StatisticFeatureRun::query()->where('stat_code', 'STAT-25')->sole();
        // Artificial upstream failure, distinct from a run that was not selected.
        StatisticFeatureResult::query()->where('feature_run_id', $upstream->id)->delete();
        DB::table('statistic_feature_run_items')->where('feature_run_id', $upstream->id)->update(['status' => 'FAILED', 'feature_result_count' => 0]);
        $upstream->forceFill(['status' => 'PARTIALLY_SUCCEEDED', 'error_count' => 1, 'success_count' => 0,
            'partial_count' => 0, 'error_summary' => json_encode([['race_id' => $args['--race-id'], 'error_message' => 'SYNTHETIC']])])->save();
        $this->artisan('keirin:statistics:build-stat20-acquired-history', $args)->assertExitCode(0);
        $run = StatisticFeatureRun::query()->where('stat_code', 'STAT-20')->sole();
        $this->assertSame(5, $run->invalid_count);
        $out = StatisticFeatureResult::query()->where('feature_run_id', $run->id)->where('bike_number', 1)->sole();
        $this->assertSame('SOURCE_RESULT_MISSING', $out->features['STAT_QUALITY']['STAT-25']['reason']);
        $this->assertContains('UPSTREAM_FAILURE_OR_UNATTRIBUTED_MISSING:STAT-25', $out->evidence['blocked_reasons']);
        $args['--source-run-id'] = array_values(array_diff($args['--source-run-id'], [$upstream->id]));
        $this->artisan('keirin:statistics:build-stat20-acquired-history', $args)->assertExitCode(0);
        $latest = StatisticFeatureResult::query()->where('stat_code', 'STAT-20')->orderByDesc('id')->firstOrFail();
        $this->assertSame('SOURCE_RUN_NOT_SELECTED', $latest->features['STAT_QUALITY']['STAT-25']['reason']);
        $this->assertNotContains('UPSTREAM_FAILURE_OR_UNATTRIBUTED_MISSING:STAT-25', $latest->evidence['blocked_reasons']);
        $this->assertSame(1, $latest->features['SOURCE_RESULT_COVERAGE']['counts']['DATA_SOURCE_PENDING']);
    }

    private function rows(): array
    {
        return [StatisticFeatureRun::query()->orderBy('id')->get()->toArray(), StatisticFeatureResult::query()->orderBy('id')->get()->toArray()];
    }

    private function fixture(int $n = 9): array
    {
        // Synthetic transaction/identity controls; real acceptance uses the sealed SQLite separately.
        $race = Fixture::race($n);
        $root = Fixture::stat01($race);
        $from = new \DateTimeImmutable('2022-01-01T00:00:00+09:00');
        $options = new Batch02BuildOptionsDto($root, $from, null, null, $race, 1, false);
        app(Batch02FeatureBuildService::class)->build($options);
        app(Batch03FeatureBuildService::class)->build(new Batch03BuildOptionsDto($root, $from, null, null, $race, 1, false));
        app(Batch04FeatureBuildService::class)->build(new Batch04BuildOptionsDto($root, $from, null, null, $race, 1, false));
        app(Batch05FeatureBuildService::class)->build(new Batch05BuildOptionsDto($root, null, null, $race, 1, false));
        foreach (['STAT-09', 'STAT-16', 'STAT-25', 'STAT-27', 'STAT-44'] as $stat) {
            app(AcquiredHistoryFeatureBuildService::class)->build($options, $stat);
        }

        return ['--stat01-run-id' => $root, '--source-run-id' => StatisticFeatureRun::query()->where('id', '!=', $root)->pluck('id')->all(), '--race-id' => $race];
    }
}
