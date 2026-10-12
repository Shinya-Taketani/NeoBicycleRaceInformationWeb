<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Statistics\Repositories\Batch03HistoricalRaceRepository;
use App\Domain\Keirin\Statistics\Repositories\Batch04TargetRepository;
use App\Domain\Keirin\Statistics\Repositories\Batch05TargetRepository;
use App\Domain\Keirin\Statistics\Repositories\HistoricalRaceRepository;
use App\Models\StatisticFeatureRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\StatCategory1Fixture;
use Tests\TestCase;

class StatCategory1AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('unfinishedSources')]
    public function test_downstream_batches_reject_nonterminal_or_unfinished_source_runs(string $repository, string $status, bool $finished): void
    {
        $id = StatCategory1Fixture::stat01(StatCategory1Fixture::race());
        StatisticFeatureRun::query()->findOrFail($id)->forceFill([
            'status' => $status, 'finished_at' => $finished ? now() : null,
        ])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STAT-01 run was not complete');
        app($repository)->validatedStat01Run($id);
    }

    public static function unfinishedSources(): array
    {
        $cases = [];
        foreach ([HistoricalRaceRepository::class, Batch03HistoricalRaceRepository::class,
            Batch04TargetRepository::class, Batch05TargetRepository::class] as $repository) {
            foreach ([['RUNNING', false], ['FAILED', true], ['SUCCEEDED', false], ['PARTIALLY_SUCCEEDED', false]] as [$status, $finished]) {
                $cases[$repository.'-'.$status] = [$repository, $status, $finished];
            }
        }

        return $cases;
    }

    #[DataProvider('finishedSources')]
    public function test_downstream_batches_accept_finished_successful_and_partial_source_runs(string $repository, string $status): void
    {
        $id = StatCategory1Fixture::stat01(StatCategory1Fixture::race());
        StatisticFeatureRun::query()->findOrFail($id)->forceFill(['status' => $status, 'finished_at' => now()])->save();

        $this->assertSame($id, app($repository)->validatedStat01Run($id)->id);
    }

    public static function finishedSources(): array
    {
        $cases = [];
        foreach ([HistoricalRaceRepository::class, Batch03HistoricalRaceRepository::class,
            Batch04TargetRepository::class, Batch05TargetRepository::class] as $repository) {
            foreach (['SUCCEEDED', 'PARTIALLY_SUCCEEDED'] as $status) {
                $cases[$repository.'-'.$status] = [$repository, $status];
            }
        }

        return $cases;
    }
}
