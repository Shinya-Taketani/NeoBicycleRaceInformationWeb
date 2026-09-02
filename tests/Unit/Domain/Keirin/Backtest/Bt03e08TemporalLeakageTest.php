<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Objective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Scorer;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class Bt03e08TemporalLeakageTest extends TestCase
{
    public function test_outer_2024_and_2025_candidates_do_not_depend_on_their_evaluation_outcomes(): void
    {
        $layout = $this->layout();
        $optimizer = new Bt03e08FistaOptimizer(new Bt03e08WinnerConditionedP3Objective);
        $training22 = $this->races(2022, 3);
        $training23 = $this->races(2023, 4);
        $outcome24A = $this->races(2024, 3);
        $outcome24B = $this->races(2024, 5);
        $fit24A = $optimizer->fit(fn (): array => [...$training22, ...$training23], $layout, 1.0);
        $fit24B = $optimizer->fit(fn (): array => [...$training22, ...$training23], $layout, 1.0);
        $this->assertNotSame($outcome24A, $outcome24B);
        $this->assertSame(get_object_vars($fit24A), get_object_vars($fit24B));
        $candidate24A = $this->prediction($fit24A, $layout);
        $candidate24B = $this->prediction($fit24B, $layout);
        $this->assertSame($candidate24A, $candidate24B);

        $training24 = $outcome24A;
        $outcome25A = $this->races(2025, 3);
        $outcome25B = $this->races(2025, 5);
        $fit25A = $optimizer->fit(fn (): array => [...$training22, ...$training23, ...$training24], $layout, 1.0);
        $fit25B = $optimizer->fit(fn (): array => [...$training22, ...$training23, ...$training24], $layout, 1.0);
        $this->assertNotSame($outcome25A, $outcome25B);
        $this->assertSame(get_object_vars($fit25A), get_object_vars($fit25B));
        $this->assertSame($this->prediction($fit25A, $layout), $this->prediction($fit25B, $layout));
    }

    public function test_2024_outcome_changes_only_affect_outer_2025_after_temporal_opening(): void
    {
        $layout = $this->layout();
        $optimizer = new Bt03e08FistaOptimizer(new Bt03e08WinnerConditionedP3Objective);
        $training = [...$this->races(2022, 3), ...$this->races(2023, 4)];
        $outer24Before = $optimizer->fit(fn (): array => $training, $layout, 1.0);
        $outer24After = $optimizer->fit(fn (): array => $training, $layout, 1.0);
        $this->assertSame(get_object_vars($outer24Before), get_object_vars($outer24After));
        $outer25A = $optimizer->fit(fn (): array => [...$training, ...$this->races(2024, 3)], $layout, 1.0);
        $outer25B = $optimizer->fit(fn (): array => [...$training, ...$this->races(2024, 5)], $layout, 1.0);
        $this->assertNotSame($outer25A->coefficients, $outer25B->coefficients);
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e08Contract::STAT_CODES as $statCode) {
            $bins[$statCode] = array_map(static fn (int $index): EffectBinDto => new EffectBinDto($index, 'CATEGORY', null, null, (string) $index, 1), range(1, 5));
        }

        return new Bt03e02ParameterLayout($bins);
    }

    /** @return list<array<string,mixed>> */
    private function races(int $year, int $thirdBike): array
    {
        $races = [];
        foreach (range(1, 12) as $race) {
            $entries = [];
            foreach (range(1, 5) as $bike) {
                $bins = array_fill(0, count(Bt03e08Contract::STAT_CODES), null);
                $bins[0] = $bike - 1;
                $rank = $bike === 1 ? 1 : ($bike === $thirdBike ? 3 : $bike + 3);
                $entries[] = ['id' => $year * 1000 + $race * 10 + $bike, 'bike' => $bike, 'anchor' => ($bike - 3.0) / 2.0, 'bins' => $bins, 'rank' => $rank, 'status' => 'FINISHED'];
            }
            $races[] = ['year' => $year, 'race_id' => $year * 100 + $race, 'entries' => $entries];
        }

        return $races;
    }

    /** @return array<string,mixed> */
    private function prediction(object $fit, Bt03e02ParameterLayout $layout): array
    {
        $race = $this->races(2024, 3)[0];
        unset($layout);

        return (new Bt03e08WinnerConditionedP3Scorer(new Bt03e03ProbabilityScorer, new CanonicalHasher))->predict($race, $fit, 1);
    }
}
