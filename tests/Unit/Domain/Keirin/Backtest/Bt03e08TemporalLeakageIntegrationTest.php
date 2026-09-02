<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08P1Q2FrozenDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Objective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Scorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\Bt03e08FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e08PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e08ValidationLossSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class Bt03e08TemporalLeakageIntegrationTest extends TestCase
{
    public function test_outer_2024_candidate_is_independent_of_2024_evaluation_outcomes(): void
    {
        $original = $this->runWalkForward([]);
        $changed = $this->runWalkForward([2024 => true]);

        $this->assertSame($original['outer_2024'], $changed['outer_2024']);
        $this->assertNotSame($original['evaluation'][2024], $changed['evaluation'][2024]);
    }

    public function test_2025_outcomes_affect_only_2025_evaluation_and_neither_outer_candidate(): void
    {
        $original = $this->runWalkForward([]);
        $changed = $this->runWalkForward([2025 => true]);

        $this->assertSame($original['outer_2024'], $changed['outer_2024']);
        $this->assertSame($original['outer_2025'], $changed['outer_2025']);
        $this->assertSame($original['evaluation'][2024], $changed['evaluation'][2024]);
        $this->assertNotSame($original['evaluation'][2025], $changed['evaluation'][2025]);
    }

    public function test_2024_outcomes_open_only_after_outer_2024_is_sealed_and_can_change_outer_2025_model(): void
    {
        $original = $this->runWalkForward([]);
        $changed = $this->runWalkForward([2024 => true]);

        $this->assertSame($original['outer_2024'], $changed['outer_2024']);
        $this->assertNotSame(
            $original['outer_2025']['model']['p3_coefficients'],
            $changed['outer_2025']['model']['p3_coefficients'],
        );
    }

    /** @param array<int,bool> $changedOutcomes @return array<string,mixed> */
    private function runWalkForward(array $changedOutcomes): array
    {
        $layout = $this->layout();
        $objective = new Bt03e08WinnerConditionedP3Objective;
        $optimizer = new Bt03e08FistaOptimizer($objective);
        $selector = new Bt03e08OneSeSelector;
        $raw = [];
        foreach (Bt03e08Contract::DEVELOPMENT_YEARS as $year) {
            $raw[$year] = $this->races($year, $changedOutcomes[$year] ?? false);
        }

        $innerA = $this->fitValidationFold($optimizer, $objective, $layout, [$raw[2022]], $raw[2023]);
        try {
            $selection2024 = $selector->select([2023 => $innerA], 40);
            $outer2024 = $this->fitCandidate($optimizer, $layout, [$raw[2022], $raw[2023]], $selection2024, 2024);
            $innerB = $this->fitValidationFold($optimizer, $objective, $layout, [$raw[2022], $raw[2023]], $raw[2024]);
            try {
                $selection2025 = $selector->select([2023 => $innerA, 2024 => $innerB], 40);
                $outer2025 = $this->fitCandidate($optimizer, $layout, [$raw[2022], $raw[2023], $raw[2024]], $selection2025, 2025);
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

    /** @param list<list<array<string,mixed>>> $trainingYears @param list<array<string,mixed>> $validation */
    private function fitValidationFold(
        Bt03e08FistaOptimizer $optimizer,
        Bt03e08WinnerConditionedP3Objective $objective,
        Bt03e02ParameterLayout $layout,
        array $trainingYears,
        array $validation,
    ): Bt03e08ValidationLossSpool {
        $path = $optimizer->fitPath($this->source($trainingYears), $layout);
        $losses = new Bt03e08ValidationLossSpool(
            sys_get_temp_dir().'/bt03e08-temporal-loss-'.bin2hex(random_bytes(8)).'.bin',
            array_keys($path['fits']),
        );
        foreach ($validation as $race) {
            $row = [];
            foreach ($path['fits'] as $key => $fit) {
                $row[$key] = $objective->raceLoss($race, $layout, $fit->coefficients);
            }
            $losses->append($row);
        }
        $losses->seal();

        return $losses;
    }

    /** @param list<list<array<string,mixed>>> $trainingYears @param array<string,mixed> $selection @return array<string,mixed> */
    private function fitCandidate(
        Bt03e08FistaOptimizer $optimizer,
        Bt03e02ParameterLayout $layout,
        array $trainingYears,
        array $selection,
        int $year,
    ): array {
        $refit = $optimizer->fitSelectedViaPath($this->source($trainingYears), $layout, $selection['lambda']);
        $fit = $refit['fit'];
        $hasher = new CanonicalHasher;
        $sourceDecoder = new Bt03e06WinnerConditionedDecoder(new Bt03e03ProbabilityScorer, $hasher);
        $decoder = new Bt03e08P1Q2FrozenDecoder($sourceDecoder, $hasher);
        $p3Scorer = new Bt03e08WinnerConditionedP3Scorer(new Bt03e03ProbabilityScorer, $hasher);
        $sourceIdentity = ['year' => $year, 'frozen_e03_source' => str_repeat((string) ($year - 2024), 64)];
        $manifest = new Bt03e08PredictionManifestAccumulator($year, $sourceIdentity, $hasher);
        $decisions = [];
        foreach (range(1, 6) as $raceOffset) {
            $predictionRace = $this->withoutOutcomes($this->race($year, $raceOffset, false));
            $source = (new Bt03e03ProbabilityScorer)->predict($predictionRace, $this->sourceFit($layout));
            $frozen = $sourceDecoder->decode($source);
            $p3 = $p3Scorer->predict($predictionRace, $fit, $frozen['primary_position_1_bike']);
            $decision = $decoder->decode($source, $p3);
            $manifest->append($decision);
            $decisions[] = $decision;
        }

        return [
            'lambda_selection' => $selection,
            'model' => $this->model($fit, $refit),
            'prediction_manifest' => $manifest->seal(),
            'decisions' => $decisions,
        ];
    }

    /** @param list<array<string,mixed>> $races @param list<array<string,mixed>> $decisions @return array<string,mixed> */
    private function evaluate(array $races, array $decisions): array
    {
        $metrics = new Bt03e08MetricEvaluator(new Bt03e05MetricEvaluator);
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
        foreach (Bt03e08Contract::STAT_CODES as $statCode) {
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
        return array_map(fn (int $offset): array => $this->race($year, $offset, $changed), range(1, 6));
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
            foreach (array_keys(Bt03e08Contract::STAT_CODES) as $statOffset) {
                $bins[] = $statOffset * 2 + (($bike + $raceOffset + $statOffset) % 2);
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
            $entry['rank'] = null;
            $entry['status'] = 'PREDICTION_ONLY';

            return $entry;
        }, $race['entries']);

        return $race;
    }

    private function sourceFit(Bt03e02ParameterLayout $layout): Bt03e03FitResultDto
    {
        $coefficients = array_fill_keys(
            ['POSITION_1', 'POSITION_2', 'POSITION_3'],
            array_fill(0, $layout->size(), 0.0),
        );

        return new Bt03e03FitResultDto(0.0, $coefficients, [], [], [], []);
    }

    /** @param array<string,mixed> $refit @return array<string,mixed> */
    private function model(Bt03e08FitResultDto $fit, array $refit): array
    {
        return [
            'lambda' => $fit->lambda,
            'p3_coefficients' => $fit->coefficients,
            'objective' => $fit->objective,
            'iterations' => $fit->iterations,
            'optimizer_diagnostics' => $fit->diagnostics,
            'refit_path' => [
                'selected_lambda' => $refit['selected_lambda'],
                'fit_order' => $refit['fit_order'],
                'candidate_statuses' => $refit['candidate_statuses'],
            ],
        ];
    }
}
