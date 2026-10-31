<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\SchoolProfileIndexOptionsDto;
use App\Domain\Keirin\Statistics\Services\AcquiredHistoryFeatureBuildService;
use App\Domain\Keirin\Statistics\Support\SchoolProfiles\Files;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\SchoolProfileFixture as Fixture;
use Tests\Support\StatCategory1Fixture;
use Tests\TestCase;
use Throwable;

final class SchoolProfileConnectionTest extends TestCase
{
    use RefreshDatabase;

    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime'] as $dir) {
                if (! mkdir($root.'/'.$dir, 0700)) {
                    throw new RuntimeException('Cannot create school test isolation.');
                }
            }
            Files::write($root.'/.env', "# Isolated school test. No application secrets.\n");
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
        } catch (Throwable $e) {
            $this->temporary->retire($this->temporary->path(), 'TEST_FAILURE');
            $this->temporary = null;
            throw $e;
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

    private function target(): array
    {
        $race = StatCategory1Fixture::race(5, '2025-01-04');
        foreach (DB::table('race_entries')->where('race_id', $race)->get() as $entry) {
            DB::table('race_entries')->where('id', $entry->id)->update(['external_player_id' => sprintf('%06d', $entry->bike_number)]);
        }
        $run = StatCategory1Fixture::stat01($race);

        return [new Batch02BuildOptionsDto($run, new DateTimeImmutable('2024-01-01T00:00:00+09:00'), null, null, $race, 10, false),
            ['--stat' => 'STAT-44', '--stat01-run-id' => $run, '--history-from' => '2024-01-01', '--race-id' => $race]];
    }

    private function index(array $profiles): SchoolProfileIndexOptionsDto
    {
        $root = $this->temporary->path().'/school-'.bin2hex(random_bytes(4));
        mkdir($root, 0700);
        $input = Fixture::manifest($root.'/input', $profiles);
        $this->artisan('keirin:statistics:build-school-profile-catalog', ['--source-manifest' => $input['path'],
            '--source-manifest-sha256' => $input['sha256'], '--output' => $root.'/catalog'])->assertExitCode(0);
        $manifest = $root.'/catalog/manifest.json';
        $this->artisan('keirin:statistics:select-school-profiles', ['--catalog' => $manifest,
            '--catalog-sha256' => Files::seal($manifest)['sha256'], '--output' => $root.'/selection.json'])->assertExitCode(0);

        return new SchoolProfileIndexOptionsDto($root.'/selection.json', Files::seal($root.'/selection.json')['sha256']);
    }

    private function semantics(int $run): array
    {
        return StatisticFeatureResult::query()->where('feature_run_id', $run)->orderBy('bike_number')->get()
            ->map(fn ($r) => [$r->features, $r->evidence, $r->status, $r->quality_status, $r->input_hash])->all();
    }

    public function test_multiple_people_connect_in_one_run_and_single_and_no_school_paths_remain_equivalent(): void
    {
        [$options] = $this->target();
        $p = Fixture::profile();
        $q = Fixture::profile('000002', 2, '2011-02-03');
        $index = $this->index([$p, $q]);
        $service = app(AcquiredHistoryFeatureBuildService::class);
        $none = $service->build($options, 'STAT-44');
        $single = $service->build($options, 'STAT-44', $p);
        $a = $service->build($options, 'STAT-44', schoolIndex: $index);
        $b = $service->build($options, 'STAT-44', schoolIndex: $index);
        $x = $this->semantics($a['run_id']);
        $this->assertSame($x, $this->semantics($b['run_id']));
        $this->assertSame($this->semantics($single['run_id'])[0], $x[0]);
        $this->assertSame('2010-01-01', $x[0][0]['debut_date']);
        $this->assertSame('2011-02-03', $x[1][0]['debut_date']);
        $this->assertSame($q['source'], $x[1][1]['debut_evidence']);
        $this->assertSame(array_slice($this->semantics($none['run_id']), 2), array_slice($x, 2));
        $this->assertSame(['CONNECTED' => 2, 'NO_CANDIDATE' => 3], $a['school_connection']['reasons']);
        $this->assertSame(5, $a['school_connection']['evaluated_entries']);
        $this->assertArrayNotHasKey('school_profile_selection', StatisticFeatureRun::query()->findOrFail($none['run_id'])->parameters);
        $this->assertSame($index->sha256, StatisticFeatureRun::query()->findOrFail($a['run_id'])->parameters['school_profile_selection']['selection_sha256']);
    }

    public function test_order_unrelated_person_and_catalog_location_do_not_change_stat44_hash(): void
    {
        [$options] = $this->target();
        $p = Fixture::profile();
        $a = $this->index([$p, Fixture::profile(log: 2)]);
        $b = $this->index([Fixture::profile('000099', 3), Fixture::profile(log: 2), $p]);
        $service = app(AcquiredHistoryFeatureBuildService::class);
        $one = $service->build($options, 'STAT-44', schoolIndex: $a);
        $two = $service->build($options, 'STAT-44', schoolIndex: $b);
        $this->assertNotSame($a->sha256, $b->sha256);
        $this->assertSame($this->semantics($one['run_id']), $this->semantics($two['run_id']));
    }

    public function test_unselected_conflict_missing_invalid_and_no_candidate_are_counted_without_invented_dates(): void
    {
        [$options] = $this->target();
        $bad = Fixture::profile('000003', 4, null);
        $bad['original_fields']['debut_date_raw'] = 'unparseable';
        $index = $this->index([Fixture::profile(), Fixture::profile(log: 2, debut: '2011-01-01'),
            Fixture::profile('000002', 3, null), $bad]);
        $out = app(AcquiredHistoryFeatureBuildService::class)->build($options, 'STAT-44', schoolIndex: $index);
        $this->assertSame(['DEBUT_DATE_CONFLICT' => 1, 'MISSING_DEBUT_DATE' => 1, 'INVALID_DEBUT_DATE' => 1, 'NO_CANDIDATE' => 2], $out['school_connection']['reasons']);
        $this->assertSame(0, $out['school_connection']['connected']);
        $this->assertSame(5, $out['school_connection']['unconnected']);
        foreach ($this->semantics($out['run_id']) as $result) {
            $this->assertNull($result[0]['debut_date']);
            $this->assertNull($result[1]['debut_evidence']);
        }
    }

    public function test_dry_run_catalog_selection_and_stat44_have_no_writes(): void
    {
        [$options, $args] = $this->target();
        $index = $this->index([Fixture::profile()]);
        $writes = [];
        DB::listen(function ($q) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|drop|alter|replace)/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $before = Files::seal($index->path);
        $this->artisan('keirin:statistics:build-acquired-history', $args + ['--school-profile-index' => $index->path,
            '--school-profile-index-sha256' => $index->sha256, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame([], $writes);
        $this->assertSame($before, Files::seal($index->path));
        $this->assertSame(0, StatisticFeatureRun::query()->where('stat_code', 'STAT-44')->count());
    }

    public function test_school_connected_44_is_audited_by_existing_20_v3_and_bad_parent_is_rejected(): void
    {
        [$options] = $this->target();
        $index = $this->index([Fixture::profile(), Fixture::profile('000002', 2)]);
        $service = app(AcquiredHistoryFeatureBuildService::class);
        $grade = $service->build($options, 'STAT-09', null, 'grade-breakdown-v3');
        $school = $service->build($options, 'STAT-44', schoolIndex: $index);
        $args = ['--stat01-run-id' => $options->stat01RunId, '--race-id' => $options->raceId,
            '--source-contract' => 'STAT-20-acquired-history-v3', '--source-run-id' => [$grade['run_id'], $school['run_id']]];
        $passes = [];
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('keirin:statistics:build-stat20-acquired-history', $args)->assertExitCode(0);
            $run = (int) DB::table('statistic_feature_runs')->max('id');
            $passes[] = $this->semantics($run);
            $this->assertSame('STAT-44-acquired-longitudinal-v2', $passes[$i][0][0]['STAT_QUALITY']['STAT-44']['calculation_version']);
            $this->assertSame('STAT-20-acquired-history-v3', $passes[$i][0][1]['source_contract']);
        }
        $this->assertSame($passes[0], $passes[1]);
        $r = StatisticFeatureResult::query()->where('feature_run_id', $school['run_id'])->firstOrFail();
        $r->forceFill(['evidence' => array_replace($r->evidence, ['stat01_input_hash' => str_repeat('0', 64)])])->save();
        $this->artisan('keirin:statistics:build-stat20-acquired-history', $args)->assertExitCode(1);
    }

    #[DataProvider('badOptions')]
    public function test_cli_option_misuse_is_rejected_before_run_writes(array $changes): void
    {
        [, $args] = $this->target();
        $index = $this->index([Fixture::profile()]);
        $request = $args + ['--school-profile-index' => $index->path, '--school-profile-index-sha256' => $index->sha256];
        $request = array_replace($request, $changes);
        $this->artisan('keirin:statistics:build-acquired-history', $request)->assertExitCode(1);
        $this->assertSame(1, StatisticFeatureRun::query()->count());
    }

    public static function badOptions(): array
    {
        return [[['--stat' => 'STAT-25']], [['--school-profile-index-sha256' => str_repeat('0', 64)]],
            [['--school-profile' => '/missing', '--school-profile-sha256' => str_repeat('a', 64)]],
            [['--school-profile-index' => null]], [['--school-profile-index-sha256' => null]],
            [['--school-profile-sha256' => str_repeat('a', 64)]]];
    }

    public function test_service_rejects_cross_stat_mixed_modes_and_unknown_contract_before_database_writes(): void
    {
        [$options] = $this->target();
        $index = $this->index([Fixture::profile()]);
        foreach (['cross', 'mixed', 'contract'] as $type) {
            $used = $index;
            if ($type === 'contract') {
                $plan = json_decode(file_get_contents($index->path), true);
                $plan['contract'] = 'UNKNOWN';
                $path = $this->temporary->path().'/bad-contract.json';
                Files::write($path, Files::json($plan));
                $used = new SchoolProfileIndexOptionsDto($path, Files::seal($path)['sha256']);
            }
            try {
                app(AcquiredHistoryFeatureBuildService::class)->build($options, $type === 'cross' ? 'STAT-09' : 'STAT-44',
                    $type === 'mixed' ? Fixture::profile() : null, schoolIndex: $used);
                $this->fail('Invalid school service input accepted.');
            } catch (\InvalidArgumentException|RuntimeException) {
                $this->assertSame(1, StatisticFeatureRun::query()->count());
            }
        }
    }

    public function test_future_debut_and_storage_failure_rollback_and_retain_failed_item(): void
    {
        [$options] = $this->target();
        $future = $this->index([Fixture::profile(debut: '2025-01-05')]);
        try {
            app(AcquiredHistoryFeatureBuildService::class)->build($options, 'STAT-44', schoolIndex: $future);
            $this->fail('Future debut accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, StatisticFeatureResult::query()->where('stat_code', 'STAT-44')->count());
            $this->assertSame('FAILED', DB::table('statistic_feature_run_items')->where('race_id', $options->raceId)->value('status'));
        }
        $index = $this->index([Fixture::profile(), Fixture::profile('000002', 2)]);
        DB::unprepared("CREATE TRIGGER artificial_school_save_fail BEFORE INSERT ON statistic_feature_results WHEN NEW.stat_code='STAT-44' AND NEW.bike_number=2 BEGIN SELECT RAISE(ABORT, 'ARTIFICIAL_SCHOOL_SAVE_FAILURE'); END");
        try {
            app(AcquiredHistoryFeatureBuildService::class)->build($options, 'STAT-44', schoolIndex: $index);
            $this->fail('Artificial storage failure not reached.');
        } catch (QueryException) {
            $run = StatisticFeatureRun::query()->orderByDesc('id')->firstOrFail();
            $this->assertSame(1, $run->error_count);
            $this->assertSame('FAILED', $run->status);
            $this->assertSame(0, $run->parameters['school_connection']['connected']);
            $this->assertSame(0, StatisticFeatureResult::query()->where('feature_run_id', $run->id)->count());
        }
    }
}
