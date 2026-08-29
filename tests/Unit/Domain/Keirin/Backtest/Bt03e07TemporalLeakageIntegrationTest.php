<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07P1FrozenDecisionDecoder;
use App\Domain\Keirin\Backtest\DTO\Bt03e07FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e07PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e07ValidationLossSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class Bt03e07TemporalLeakageIntegrationTest extends TestCase
{
    public function test_outer_2024_candidate_is_independent_of_2024_outcomes(): void
    {
        $original = $this->runWalkForward([]);
        $changed = $this->runWalkForward([2024 => true]);

        $this->assertSame($original['outer_2024'], $changed['outer_2024']);
        $this->assertNotSame($original['evaluation'][2024], $changed['evaluation'][2024]);
    }

    public function test_2025_outcomes_affect_only_evaluation_and_neither_outer_candidate(): void
    {
        $original = $this->runWalkForward([]);
        $changed = $this->runWalkForward([2025 => true]);

        $this->assertSame($original['outer_2024'], $changed['outer_2024']);
        $this->assertSame($original['outer_2025'], $changed['outer_2025']);
        $this->assertSame($original['evaluation'][2024], $changed['evaluation'][2024]);
        $this->assertNotSame($original['evaluation'][2025], $changed['evaluation'][2025]);
    }

    public function test_2024_outcomes_open_only_for_outer_2025_training_and_validation(): void
    {
        $original = $this->runWalkForward([]);
        $changed = $this->runWalkForward([2024 => true]);

        $this->assertSame($original['outer_2024'], $changed['outer_2024']);
        $this->assertNotSame($original['outer_2025']['model'], $changed['outer_2025']['model']);
        $this->assertNotSame(
            [$original['outer_2025']['lambda_selection'], $original['outer_2025']['model']],
            [$changed['outer_2025']['lambda_selection'], $changed['outer_2025']['model']],
        );
    }

    /** @param array<int,bool> $changedOutcomes @return array<string,mixed> */
    private function runWalkForward(array $changedOutcomes): array
    {
        $layout = $this->layout();
        $objective = new Bt03e07DirectPositionObjective;
        $optimizer = new Bt03e07FistaOptimizer($objective);
        $selector = new Bt03e07OneSeSelector;

        $raw = [];
        foreach (Bt03e07Contract::DEVELOPMENT_YEARS as $year) {
            $raw[$year] = $this->races($year, $changedOutcomes[$year] ?? false);
        }

        $innerA = $this->fitValidationFold($optimizer, $objective, $layout, [$raw[2022]], $raw[2023]);
        try {
            $outer2024Selection = $selector->select([2023 => $innerA]);
            $outer2024 = $this->fitCandidate(
                $optimizer,
                $layout,
                [$raw[2022], $raw[2023]],
                $outer2024Selection,
                2024,
            );

            $innerB = $this->fitValidationFold($optimizer, $objective, $layout, [$raw[2022], $raw[2023]], $raw[2024]);
            try {
                $outer2025Selection = $selector->select([2023 => $innerA, 2024 => $innerB]);
                $outer2025 = $this->fitCandidate(
                    $optimizer,
                    $layout,
                    [$raw[2022], $raw[2023], $raw[2024]],
                    $outer2025Selection,
                    2025,
                );
            } finally {
                $innerB->cleanup();
            }
        } finally {
            $innerA->cleanup();
        }

        return [
            'outer_2024' => $outer2024,
            'outer_2025' => $outer2025,
            'evaluation' => [
                2024 => $this->evaluate($raw[2024], $outer2024['decisions']),
                2025 => $this->evaluate($raw[2025], $outer2025['decisions']),
            ],
        ];
    }

    /**
     * @param  list<list<array<string,mixed>>>  $trainingYears
     * @param  list<array<string,mixed>>  $validation
     */
    private function fitValidationFold(
        Bt03e07FistaOptimizer $optimizer,
        Bt03e07DirectPositionObjective $objective,
        Bt03e02ParameterLayout $layout,
        array $trainingYears,
        array $validation,
    ): Bt03e07ValidationLossSpool {
        $path = $optimizer->fitPath($this->source($trainingYears), $layout);
        $losses = new Bt03e07ValidationLossSpool(
            sys_get_temp_dir().'/bt03e07-temporal-loss-'.bin2hex(random_bytes(8)).'.bin',
            array_keys($path['fits']),
        );
        foreach ($validation as $race) {
            $row = [];
            foreach ($path['fits'] as $key => $fit) {
                foreach (Bt03e07Contract::POSITIONS as $position) {
                    $row[$key][$position] = $objective->raceLoss(
                        $race,
                        $layout,
                        $fit->coefficients[$position],
                        $position,
                    );
                }
            }
            $losses->append($row);
        }
        $losses->seal();

        return $losses;
    }

    /**
     * @param  list<list<array<string,mixed>>>  $trainingYears
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    private function fitCandidate(
        Bt03e07FistaOptimizer $optimizer,
        Bt03e02ParameterLayout $layout,
        array $trainingYears,
        array $selection,
        int $year,
    ): array {
        $refit = $optimizer->fitSelectedViaPath($this->source($trainingYears), $layout, $selection['lambda']);
        $fit = $refit['fit'];
        $hasher = new CanonicalHasher;
        $scorer = new Bt03e07DirectPositionScorer($hasher);
        $decoder = new Bt03e07P1FrozenDecisionDecoder;
        $sourceIdentity = ['year' => $year, 'frozen_source' => str_repeat((string) ($year - 2024), 64)];
        $manifest = new Bt03e07PredictionManifestAccumulator($year, $sourceIdentity, $hasher);
        $decisions = [];
        foreach (range(1, 5) as $raceOffset) {
            $features = $this->race($year, $raceOffset, false);
            $decision = $decoder->decode(
                $this->sourcePrediction($year, $raceOffset),
                $scorer->predict($this->withoutOutcomes($features), $fit),
            );
            $manifest->append($decision);
            $decisions[] = $decision;
        }

        return [
            'lambda_selection' => $selection,
            'model' => $this->model($fit),
            'prediction_manifest' => $manifest->seal(),
            'decisions' => $decisions,
        ];
    }

    /** @param list<array<string,mixed>> $races @param list<array<string,mixed>> $decisions @return array<string,mixed> */
    private function evaluate(array $races, array $decisions): array
    {
        $metrics = new Bt03e07MetricEvaluator(new Bt03e05MetricEvaluator);
        $summary = $metrics->emptySummary();
        foreach ($races as $offset => $race) {
            $metrics->add($summary, $metrics->raceComparison($race, $decisions[$offset]));
        }

        return $metrics->finish($summary);
    }

    /** @param list<list<array<string,mixed>>> $years @return callable():\Generator<int,array<string,mixed>> */
    private function source(array $years): callable
    {
        return static function () use ($years): \Generator {
            foreach ($years as $races) {
                yield from $races;
            }
        };
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e07Contract::STAT_CODES as $statCode) {
            $bins[$statCode] = [
                new EffectBinDto(1, 'CATEGORY', null, null, '0', 12),
                new EffectBinDto(2, 'CATEGORY', null, null, '1', 13),
            ];
        }

        return new Bt03e02ParameterLayout($bins);
    }

    /** @return list<array<string,mixed>> */
    private function races(int $year, bool $changed): array
    {
        return array_map(fn (int $offset): array => $this->race($year, $offset, $changed), range(1, 5));
    }

    /** @return array<string,mixed> */
    private function race(int $year, int $raceOffset, bool $changed): array
    {
        $order = range(1, 5);
        for ($rotation = 1; $rotation < $raceOffset; $rotation++) {
            $order[] = array_shift($order);
        }
        if ($changed) {
            $order = [$order[0], $order[4], $order[3], $order[2], $order[1]];
        }
        $ranks = array_flip($order);
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $bins = [];
            foreach (array_keys(Bt03e07Contract::STAT_CODES) as $statOffset) {
                $category = ($bike + $raceOffset + $statOffset) % 2;
                $bins[] = $statOffset * 2 + $category;
            }
            $entries[] = [
                'id' => $year * 1000 + $raceOffset * 10 + $bike,
                'bike' => $bike,
                'raw' => 100.0 - $bike,
                'stat01_rank' => $bike,
                'anchor' => (3.0 - $bike) / 2.0,
                'bins' => $bins,
                'rank' => $ranks[$bike] + 1,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => $year, 'race_id' => $year * 100 + $raceOffset, 'entries' => $entries];
    }

    /** @param array<string,mixed> $race @return array<string,mixed> */
    private function withoutOutcomes(array $race): array
    {
        $race['entries'] = array_map(static function (array $entry): array {
            unset($entry['rank'], $entry['status']);

            return $entry;
        }, $race['entries']);

        return $race;
    }

    /** @return array<string,mixed> */
    private function sourcePrediction(int $year, int $raceOffset): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $p1 = $bike === 1 ? 0.4 : 0.15;
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => $p1,
                'position_2_probability' => 0.2,
                'position_3_probability' => 0.2,
                'top2_probability' => $p1 + 0.2,
                'top3_probability' => $p1 + 0.4,
            ];
        }

        return [
            'year' => $year,
            'race_id' => $year * 100 + $raceOffset,
            'entries' => $entries,
            'map_ordered_top3' => [1, 2, 3],
            'map_ordered_probability' => 0.1,
            'map_top3_set' => [1, 2, 3],
            'map_top3_set_probability' => 0.2,
        ];
    }

    /** @return array<string,mixed> */
    private function model(Bt03e07FitResultDto $fit): array
    {
        return [
            'lambda' => $fit->lambda,
            'position_coefficients' => $fit->coefficients,
            'objectives' => $fit->objectives,
            'iterations' => $fit->iterations,
            'optimizer_diagnostics' => $fit->diagnostics,
        ];
    }
}
