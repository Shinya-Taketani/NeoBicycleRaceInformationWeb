<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Bt01Fixture;
use Tests\TestCase;

class BacktestSourceManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_fixed_fixture_sources_are_verified(): void
    {
        Bt01Fixture::seed();
        $verified = (new BacktestFeatureRepository)->validateSources(Bt01Fixture::manifest());
        $this->assertSame([25, 26, 1, 27], array_map(fn ($source): int => $source->manifest->featureRunId, $verified));
        $this->assertSame([9, 5, 5, 5], array_map(fn ($source): int => $source->verifiedResultCount, $verified));
    }

    public function test_wrong_run_id_is_rejected_instead_of_selecting_latest_run(): void
    {
        Bt01Fixture::seed();
        $manifest = Bt01Fixture::manifest();
        $manifest[0] = new SourceManifestEntryDto(2022, 99, $manifest[0]->featureRunUuid, $manifest[0]->targetFrom, $manifest[0]->targetTo, 2, 9);
        $this->expectException(RuntimeException::class);
        (new BacktestFeatureRepository)->validateSources($manifest);
    }

    public function test_wrong_uuid_is_rejected(): void
    {
        Bt01Fixture::seed();
        DB::table('statistic_feature_runs')->where('id', 25)->update(['run_uuid' => '00000000-0000-4000-8000-999999999999']);
        $this->expectException(RuntimeException::class);
        (new BacktestFeatureRepository)->validateSources(Bt01Fixture::manifest());
    }

    public function test_wrong_version_is_rejected(): void
    {
        Bt01Fixture::seed();
        DB::table('statistic_feature_runs')->where('id', 25)->update(['calculation_version' => 'wrong']);
        $this->expectException(RuntimeException::class);
        (new BacktestFeatureRepository)->validateSources(Bt01Fixture::manifest());
    }

    public function test_wrong_date_range_is_rejected(): void
    {
        Bt01Fixture::seed();
        DB::table('statistic_feature_runs')->where('id', 25)->update(['target_to' => '2022-12-30']);
        $this->expectException(RuntimeException::class);
        (new BacktestFeatureRepository)->validateSources(Bt01Fixture::manifest());
    }

    public function test_wrong_result_count_is_rejected(): void
    {
        Bt01Fixture::seed();
        DB::table('statistic_feature_results')->where('feature_run_id', 25)->limit(1)->delete();
        $this->expectException(RuntimeException::class);
        (new BacktestFeatureRepository)->validateSources(Bt01Fixture::manifest());
    }

    public function test_source_error_count_is_rejected(): void
    {
        Bt01Fixture::seed();
        DB::table('statistic_feature_runs')->where('id', 25)->update(['error_count' => 1]);
        $this->expectException(RuntimeException::class);
        (new BacktestFeatureRepository)->validateSources(Bt01Fixture::manifest());
    }
}
