<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\ControlReuse;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Experiment;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e06ModelReconstructor;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TacticalHistoryExperimentTest extends TestCase
{
    public function test_real_experiment_dependencies_reuse_control_fit_both_outers_and_release_labels_after_seals(): void
    {
        $directory = sys_get_temp_dir().'/history-experiment-test-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $all = [];
            foreach ([2022, 2023, 2024, 2025] as $year) {
                foreach ([90, 20] as $id) {
                    $entries = [];
                    foreach (range(1, 5) as $bike) {
                        $signals = array_fill(0, 12, 0);
                        $signals[0] = $bike === 1 ? 0 : 1;
                        $entries[] = ['id' => $year * 10000 + $id * 10 + $bike, 'bike' => $bike, 'raw' => 100.0, 'stat01_rank' => 1,
                            'anchor' => 0.0, 'anchor_status' => 'ZERO_VARIANCE', 'signals' => $signals,
                            'history' => array_fill(0, 4, in_array($bike, [1, 3], true) ? 0 : 1), 'history_status' => 'AVAILABLE'];
                    }
                    $all[$year][] = ['year' => $year, 'race_id' => $year * 100 + $id, 'entries' => $entries];
                }
                JsonlArtifact::write($directory.'/inputs-'.$year.'.jsonl', $year <= 2023 ? $this->labels($all[$year]) : $all[$year]);
            }
            $bins = new EffectBinBuilder(new ExternalSortEffectBinBoundaryProvider($directory));
            $dataset = new Dataset;
            $layouts = new LayoutBuilder($bins);
            $objective = new Objective;
            $optimizer = new Optimizer($objective);
            $scorer = new Bt03e03ProbabilityScorer;
            $hasher = new CanonicalHasher;
            $predictor = new Predictor($scorer, new Bt03e06WinnerConditionedDecoder($scorer, $hasher));
            $models = [];
            $csv = fopen($directory.'/control.csv', 'xb');
            fputcsv($csv, ControlReuse::CSV_COLUMNS, escape: '');
            foreach ([2024, 2025] as $year) {
                $source = function () use ($all, $year): \Generator {
                    foreach (range(2022, $year - 1) as $trainingYear) {
                        foreach ($this->labels($all[$trainingYear]) as $race) {
                            foreach ($race['entries'] as &$entry) {
                                unset($entry['history'], $entry['history_status']);
                            }
                            unset($entry);
                            yield $race;
                        }
                    }
                };
                $layout = $layouts->build($source);
                $binned = fn () => $dataset->binned($source, $layout, $bins);
                $fit = $optimizer->fit($binned, $layout, 1.0);
                $models['outer_'.$year]['model'] = ['optimizer_version' => Bt03e03Contract::OPTIMIZER_VERSION,
                    'probability_version' => Bt03e03Contract::PROBABILITY_VERSION, 'tie_rule_version' => Bt03e03Contract::TIE_RULE_VERSION,
                    'stat01_anchor_coefficient' => 1.0, 'lambda' => 1.0, 'bins' => $layout->canonicalBins(), 'position_coefficients' => $fit->coefficients,
                    'weighted_center_means' => array_map(fn ($c) => $layout->weightedMeans($c), $fit->coefficients),
                    'objectives' => $fit->objectives, 'iterations' => $fit->iterations, 'eligible_races' => $fit->eligibleRaceCounts,
                    'excluded_races' => $fit->excludedRaceCounts, 'optimizer_diagnostics' => $fit->diagnostics];
                $raw = fn () => $dataset->raw([$directory.'/inputs-'.$year.'.jsonl'], false, true);
                foreach ($dataset->binned($raw, $layout, $bins) as $race) {
                    $decision = $predictor->predict($race, $fit, true)['decision'];
                    $row = [];
                    foreach (ControlReuse::CSV_COLUMNS as $key) {
                        $v = $decision[$key];
                        $row[] = is_float($v) ? sprintf('%.17g', $v) : (is_array($v) ? implode('-', $v) : (is_bool($v) ? (int) $v : $v));
                    }
                    fputcsv($csv, $row, escape: '');
                }
            }
            fclose($csv);
            $snapshot = new class($all, $directory) implements Bt02OutcomeContextSnapshot
            {
                public array $reads = [];

                public function __construct(private array $all, private string $directory) {}

                public function chunks(FoldDefinitionDto $fold, int $chunkSize): \Generator
                {
                    $year = (int) $fold->evaluationFrom->format('Y');
                    foreach (['C0-reuse-', 'C1-fit-'] as $prefix) {
                        if (! is_file($this->directory.'/run/'.$prefix.$year.'/predictions.jsonl.manifest.json')) {
                            throw new RuntimeException('Outer labels opened before both prediction seals.');
                        }
                    }
                    $this->reads[] = $year;
                    foreach ($this->all[$year] as $race) {
                        yield [new Bt02OutcomeContextRaceDto(new RaceContextDto($race['race_id'], new DateTimeImmutable($year.'-06-01'), null, null, 5, 'CONFIRMED'), 'A',
                            array_map(fn ($bike) => new LabelResultDto($race['race_id'], $bike, $bike, 'FINISHED'), range(1, 5)))];
                    }
                }

                public function auditParameters(): array
                {
                    return [];
                }

                public function manifestHash(): string
                {
                    return str_repeat('a', 64);
                }
            };
            $trainer = new Trainer($layouts, $dataset, $bins, $optimizer, $objective, $predictor);
            $control = new ControlReuse(new Bt03e06ModelReconstructor($hasher), $layouts, $dataset, $bins, $predictor);
            $result = (new Experiment($trainer, $control, $dataset, new Bt03e03OneSeSelector))->run($directory, $directory.'/run', $models, $directory.'/control.csv', $snapshot);
            $this->assertSame([2024, 2025], $snapshot->reads);
            $this->assertCount(2, $result['control_reuses']);
            foreach ($result['outer_paths'] as $year => $paths) {
                $predictions = iterator_to_array(JsonlArtifact::read($paths['C1']));
                $this->assertCount(2, $predictions);
                $this->assertFalse($predictions[0]['decision']['reconstruction_verified']);
                $model = json_decode(file_get_contents($directory.'/run/C1-fit-'.$year.'/model.json'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(16, $model['layout']['feature_count']);
                $this->assertSame(Bt03e03Contract::POSITIONS, array_keys($model['position_coefficients']));
            }
            mkdir($directory.'/comparison');
            $comparison = (new Evaluation(new Bt03e05MetricEvaluator, new Bt03e05PairedBootstrap(new Type7Quantile), new Bt03e05AcceptanceGate))->evaluate($result['outer_paths'], $directory.'/comparison', true);
            $this->assertSame(6.0, $comparison['outer']['C1-C0'][2024]['denominators']['POSITION_HIT_RATE_AT_3']);
            $this->assertArrayHasKey('C1-C0', $comparison['intervals']);
            $this->assertArrayHasKey('C1-STAT01', $comparison['intervals']);
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    private function labels(array $races): array
    {
        foreach ($races as &$race) {
            foreach ($race['entries'] as &$entry) {
                $entry['rank'] = $entry['bike'];
                $entry['status'] = 'FINISHED';
            }
            unset($entry);
        }
        unset($race);

        return $races;
    }
}
