<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt02LabelDefinition;
use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\FeatureEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\Bt02SignalFeatureDto;
use App\Domain\Keirin\Backtest\DTO\FeatureInputDto;
use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02SignalFeatureRepository;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02EvaluationDatasetService;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotSession;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Bt02EvaluationDatasetServiceTest extends TestCase
{
    public function test_dataset_uses_fixed_sources_and_yields_one_paired_set_with_fixed_labels(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $service = $this->service();
        $rows = iterator_to_array($service->rows(
            new DateTimeImmutable('2023-01-01'),
            new DateTimeImmutable('2023-12-31'),
            'STAT-10',
            Bt02SignalCohort::Strict,
        ), false);

        $this->assertCount(4, $rows);
        $this->assertSame([1, 2, 3, 4], array_column($rows, 'raceEntryId'));
        $this->assertTrue($rows[0]->labels->isWin);
        $this->assertTrue($rows[1]->labels->isTop2);
        $this->assertFalse($rows[3]->labels->isTop3);
        $this->assertSame(1, $rows[0]->label('IS_WIN'));
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => preg_match('/\b(races|race_results|race_payouts)\b/', $sql) === 1)));
    }

    public function test_incomplete_confirmed_results_fail_closed(): void
    {
        $service = $this->service(incompleteLabels: true);

        $this->expectException(RuntimeException::class);
        iterator_to_array($service->rows(
            new DateTimeImmutable('2023-01-01'),
            new DateTimeImmutable('2023-12-31'),
            'STAT-10',
            Bt02SignalCohort::Strict,
        ));
    }

    private function service(bool $incompleteLabels = false): Bt02EvaluationDatasetService
    {
        $race = new RaceContextDto(1, new DateTimeImmutable('2023-06-01'), new DateTimeImmutable('2023-06-01 12:00:00'), null, 5, 'CONFIRMED');
        $baselineRows = [];
        $signalRows = [];
        $labelRows = [];
        foreach (range(1, 5) as $bike) {
            $baselineRows[] = new FeatureInputDto($bike, 26, 1, $bike, $bike, $bike, 'VALID', 'FULL', new DateTimeImmutable('2023-06-01 10:00:00'), str_repeat((string) $bike, 64), (string) (80 + $bike), true, $bike);
            $signalRows[] = new Bt02SignalFeatureDto(1, $bike, 'VALID', 'FULL', [], $bike === 5 ? null : (float) $bike);
            $labelRows[] = new LabelResultDto(1, $bike, $bike, 'FINISHED');
        }
        $baseline = Mockery::mock(BacktestFeatureRepository::class);
        $baseline->shouldReceive('forRaces')->once()->with(26, [1])->andReturn([1 => $baselineRows]);
        $signals = Mockery::mock(Bt02SignalFeatureRepository::class);
        $signals->shouldReceive('forRaces')->once()->with(45, 'STAT-10', [1])->andReturn([1 => $signalRows]);
        $snapshot = Mockery::mock(Bt02OutcomeContextSnapshot::class);
        $snapshot->shouldReceive('chunks')->once()->andReturn((function () use ($race, $labelRows, $incompleteLabels): \Generator {
            yield [new Bt02OutcomeContextRaceDto(
                $race,
                'Ａ級予選',
                $incompleteLabels ? [new LabelResultDto(1, 1, 1, 'FINISHED')] : $labelRows,
            )];
        })());
        $session = new Bt02OutcomeContextSnapshotSession;
        $session->activate($snapshot);
        $registry = new Bt02SignalRegistry;

        return new Bt02EvaluationDatasetService(
            new Bt01SourceManifest(new CanonicalHasher),
            new Bt02SourceManifest,
            $session,
            $baseline,
            $signals,
            new FeatureEligibilityEvaluator,
            new Bt02SignalEligibilityEvaluator($registry),
            new Bt02LabelDefinition,
        );
    }
}
