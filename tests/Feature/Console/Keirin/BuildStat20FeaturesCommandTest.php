<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch05BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat01BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat20BuildOptionsDto;
use App\Domain\Keirin\Statistics\Services\Batch02FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch03FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch04FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch05FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Stat01FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Stat20FeatureBuildService;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StatCategory1Fixture as Fixture;
use Tests\TestCase;

class BuildStat20FeaturesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_fifteen_real_calculation_paths_connect_and_quality_is_saved_per_entry(): void
    {
        $race = Fixture::race();
        $root = Fixture::stat01($race);
        $from = new DateTimeImmutable('2023-01-01');
        app(Batch02FeatureBuildService::class)->build(new Batch02BuildOptionsDto($root, $from, null, null, $race, 1, false));
        app(Batch03FeatureBuildService::class)->build(new Batch03BuildOptionsDto($root, $from, null, null, $race, 1, false));
        app(Batch04FeatureBuildService::class)->build(new Batch04BuildOptionsDto($root, $from, null, null, $race, 1, false));
        app(Batch05FeatureBuildService::class)->build(new Batch05BuildOptionsDto($root, null, null, $race, 1, false));
        $sources = StatisticFeatureRun::query()->where('id', '!=', $root)->pluck('id')->all();
        $this->assertCount(14, $sources);
        $before = $this->businessRows();
        $upstream = StatisticFeatureResult::query()->orderBy('id')->get()->toArray();

        $this->artisan('keirin:statistics:build-stat20', [
            '--stat01-run-id' => $root, '--source-run-id' => $sources, '--race-id' => $race,
        ])->expectsOutputToContain('target_races=1 target_entries=7')->assertExitCode(0);

        $run = StatisticFeatureRun::query()->where('stat_code', 'STAT-20')->sole();
        $this->assertSame('PARTIALLY_SUCCEEDED', $run->status);
        $this->assertSame(0, $run->error_count);
        $this->assertNotNull($run->finished_at);
        $results = StatisticFeatureResult::query()->where('feature_run_id', $run->id)->orderBy('bike_number')->get();
        $this->assertCount(7, $results);
        foreach ($results as $result) {
            $this->assertCount(15, $result->features['STAT_QUALITY']);
            $this->assertSame(0, $result->features['SOURCE_RESULT_COVERAGE']['counts']['DATA_SOURCE_PENDING']);
            $this->assertSame('RACE', $result->features['STAT_QUALITY']['STAT-41']['subject_type']);
            $this->assertSame('NO_HISTORY', $result->features['STAT_QUALITY']['STAT-10']['status']);
            $this->assertNull($result->raw_points);
            $this->assertNull($result->confidence);
            $this->assertNull($result->effective_points);
            $this->assertFalse($result->evidence['historical_as_of_available']);
            $this->assertSame('NOT_AUTHORIZED', $result->evidence['prediction_use']);
        }
        $this->assertSame($before, $this->businessRows());
        $this->assertSame($upstream, StatisticFeatureResult::query()->where('stat_code', '!=', 'STAT-20')->orderBy('id')->get()->toArray());
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_dry_run_writes_no_statistics_and_does_not_query_results_raw_or_players(): void
    {
        $race = Fixture::race();
        $root = Fixture::stat01($race);
        $before = $this->statisticRows();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->artisan('keirin:statistics:build-stat20', [
                '--stat01-run-id' => $root, '--race-id' => $race, '--dry-run' => true,
            ])->expectsOutputToContain('feature_run_id=dry-run')->assertExitCode(0);
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($before, $this->statisticRows());
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|race_results|race_payouts|scraping_fetch_logs|players)\b/i', $sql);
        }
    }

    #[DataProvider('invalidRuns')]
    public function test_incomplete_version_or_mixed_snapshot_source_run_is_rejected_before_writes(array $changes): void
    {
        $race = Fixture::race();
        $root = Fixture::stat01($race);
        $source = StatisticFeatureRun::query()->findOrFail($root)->replicate(['run_uuid']);
        $source->forceFill(['run_uuid' => (string) Str::uuid(), 'stat_code' => 'STAT-10',
            'calculation_version' => 'STAT-10-existing-db-v1', 'parameters' => ['stat01_run_id' => $root]])->save();
        $source->forceFill($changes)->save();
        $before = $this->statisticRows();
        $this->artisan('keirin:statistics:build-stat20', [
            '--stat01-run-id' => $root, '--source-run-id' => [$source->id], '--race-id' => $race,
        ])->assertExitCode(1);
        $this->assertSame($before, $this->statisticRows());
    }

    public static function invalidRuns(): array
    {
        return [[['status' => 'RUNNING']], [['status' => 'FAILED']], [['finished_at' => null]],
            [['calculation_version' => 'unsupported']], [['parameters' => ['stat01_run_id' => 999]]],
            [['mode' => 'AS_OF']], [['stat_code' => 'STAT-01', 'calculation_version' => 'STAT-01-existing-db-v1']]];
    }

    public function test_invalid_arguments_and_2026_synthetic_target_are_rejected_without_writes(): void
    {
        $race = Fixture::race();
        $root = Fixture::stat01($race);
        $before = $this->statisticRows();
        foreach ([[], ['--stat01-run-id' => $root],
            ['--stat01-run-id' => $root, '--from' => '2026-01-01', '--to' => '2026-01-01'],
            ['--stat01-run-id' => $root, '--race-id' => $race, '--chunk' => 1001],
            ['--stat01-run-id' => $root, '--race-id' => $race, '--source-run-id' => [$root]],
            ['--stat01-run-id' => $root, '--from' => '2024-02-30', '--to' => '2024-03-01'],
        ] as $args) {
            $this->artisan('keirin:statistics:build-stat20', $args)->assertExitCode(1);
        }
        DB::table('races')->where('id', $race)->update(['race_date' => '2026-01-01']);
        $this->artisan('keirin:statistics:build-stat20', ['--stat01-run-id' => $root, '--race-id' => $race])->assertExitCode(1);
        $this->assertSame($before, $this->statisticRows());
    }

    public function test_identity_failure_has_no_results_and_finishes_run_failed(): void
    {
        $race = Fixture::race();
        $root = Fixture::stat01($race);
        StatisticFeatureResult::query()->where('feature_run_id', $root)->where('bike_number', 1)->sole()
            ->forceFill(['bike_number' => 9])->save();
        $this->artisan('keirin:statistics:build-stat20', ['--stat01-run-id' => $root, '--race-id' => $race])->assertExitCode(1);
        $run = StatisticFeatureRun::query()->where('stat_code', 'STAT-20')->sole();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(1, $run->error_count);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, StatisticFeatureResult::query()->where('feature_run_id', $run->id)->count());
        $this->assertSame('FAILED', DB::table('statistic_feature_run_items')->where('feature_run_id', $run->id)->sole()->status);
    }

    public function test_keyset_pages_are_chunk_invariant_and_each_run_retains_separate_audit(): void
    {
        for ($i = 0; $i < 4; $i++) {
            Fixture::race(5);
        }
        $date = new DateTimeImmutable('2024-01-02');
        $root = (int) app(Stat01FeatureBuildService::class)->build(new Stat01BuildOptionsDto($date, $date, null, 1, false))->runId;
        $service = app(Stat20FeatureBuildService::class);
        $first = $service->build(new Stat20BuildOptionsDto($root, [], $date, $date, null, 1));
        $second = $service->build(new Stat20BuildOptionsDto($root, [], $date, $date, null, 100));
        $this->assertNotSame($first->runId, $second->runId);
        foreach ([$first, $second] as $summary) {
            $this->assertSame(4, $summary->counts['processed_races']);
            $this->assertSame(20, $summary->counts['results']);
            $this->assertSame(0, $summary->counts['errors']);
            $this->assertSame(4, DB::table('statistic_feature_run_items')->where('feature_run_id', $summary->runId)->count());
        }
        $hashes = fn ($id) => StatisticFeatureResult::query()->where('feature_run_id', $id)
            ->orderBy('race_entry_id')->pluck('input_hash')->all();
        $this->assertSame($hashes($first->runId), $hashes($second->runId));
        $this->assertSame(40, StatisticFeatureResult::query()->where('stat_code', 'STAT-20')->count());
    }

    public function test_bad_race_does_not_abort_later_keyset_pages_and_reports_partial_success(): void
    {
        $bad = Fixture::race(5);
        Fixture::race(5);
        $date = new DateTimeImmutable('2024-01-02');
        $root = (int) app(Stat01FeatureBuildService::class)->build(new Stat01BuildOptionsDto($date, $date, null, 1, false))->runId;
        StatisticFeatureResult::query()->where('feature_run_id', $root)->where('race_id', $bad)->where('bike_number', 1)
            ->sole()->forceFill(['input_hash' => 'invalid'])->save();
        $summary = app(Stat20FeatureBuildService::class)->build(new Stat20BuildOptionsDto($root, [], $date, $date, null, 1));
        $this->assertSame(2, $summary->counts['processed_races']);
        $this->assertSame(5, $summary->counts['results']);
        $this->assertSame(1, $summary->counts['errors']);
        $this->assertSame('PARTIALLY_SUCCEEDED', StatisticFeatureRun::query()->findOrFail($summary->runId)->status);
        $this->assertSame(['FAILED', 'PARTIAL'], DB::table('statistic_feature_run_items')
            ->where('feature_run_id', $summary->runId)->orderBy('race_id')->pluck('status')->all());
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_malformed_source_json_is_not_replaced_with_empty_evidence(): void
    {
        $race = Fixture::race();
        $root = Fixture::stat01($race);
        DB::table('statistic_feature_results')->where('feature_run_id', $root)->where('bike_number', 1)->update(['evidence' => '[]']);
        $summary = app(Stat20FeatureBuildService::class)->build(new Stat20BuildOptionsDto($root, [], null, null, $race));
        $this->assertSame(1, $summary->counts['errors']);
        $this->assertSame(0, $summary->counts['results']);
        $this->assertStringContainsString('must be an object', StatisticFeatureRun::query()->findOrFail($summary->runId)->error_summary);
    }

    private function statisticRows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            ['statistic_feature_runs', 'statistic_feature_results', 'statistic_feature_run_items']);
    }

    private function businessRows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            ['races', 'race_entries', 'players', 'race_results', 'race_payouts', 'scraping_fetch_logs']);
    }
}
