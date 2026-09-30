<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract as Comparison;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Layout as C2Layout;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader as C2Loader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\LineWriter;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout as C1Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as C1Loader;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as Input;

/** Synthetic diagnostic fixtures: declared coefficients, no fitting or real sources. */
final class Stat35C1DiagnosticFixture
{
    public static function model(bool $extended): array
    {
        $codes = $extended ? Comparison::features() : array_slice(Comparison::features(), 0, 16);
        $bins = [];
        foreach ($codes as $offset => $code) {
            if ($offset === 16) {
                $bins[$code] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 0),
                    new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, 0.5, null, 10),
                    new EffectBinDto(3, 'NUMERIC_RANGE', 0.5, null, null, 10)];
            } elseif ($offset === 12) {
                $bins[$code] = [new EffectBinDto(1, 'CATEGORY', null, null, '0', 10), new EffectBinDto(2, 'CATEGORY', null, null, '1', 10)];
            } elseif ($extended && $offset === 0) {
                $bins[$code] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.25, null, 10),
                    new EffectBinDto(2, 'NUMERIC_RANGE', 0.25, 0.75, null, 10), new EffectBinDto(3, 'NUMERIC_RANGE', 0.75, null, null, 10)];
            } else {
                $bins[$code] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.5, null, 10), new EffectBinDto(2, 'NUMERIC_RANGE', 0.5, null, null, 10)];
            }
        }
        $layout = $extended ? new C2Layout($bins) : new C1Layout($bins);
        $model = ['experiment' => $extended ? Comparison::VERSION : HistoryAggregator::VERSION,
            'optimizer_version' => SolverContract::OPTIMIZER_VERSION, 'model_version' => $extended ? Comparison::MODEL_VERSION : SolverContract::MODEL_VERSION,
            'objective_reference_version' => Bt03e03Contract::OPTIMIZER_VERSION, 'stat01_anchor_coefficient' => 1.0, 'lambda' => 0.1,
            'layout' => ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(),
                'active_group_count_G' => count($layout->groups()), 'numeric_edge_count' => count($layout->smoothEdges()),
                'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(), 'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()]];
        foreach (Bt03e03Contract::POSITIONS as $p => $position) {
            $coefficients = array_fill(0, $layout->size(), 0.0);
            foreach ($layout->groups() as $code => $indexes) {
                $v = $code === 'STAT35_MEAN6' && $p === 1 ? 0.0 : ($extended ? 0.25 : 0.125) * ($p + 1);
                $coefficients[$indexes[0]] = $v;
                $coefficients[$indexes[count($indexes) - 1]] = -$v;
            }
            $model['position_coefficients'][$position] = $coefficients;
            $model['weighted_center_means'][$position] = $layout->weightedMeans($coefficients);
            $model['objectives'][$position] = 1.0;
            $model['iterations'][$position] = 1;
            $model['eligible_races'][$position] = 5;
            $model['excluded_races'][$position] = 0;
            $model['optimizer_diagnostics'][$position] = ['status' => 'CONVERGED', 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
                'lambda' => 0.1, 'position' => $position, 'iteration' => 1, 'accepted_update_count' => 1,
                'max_iterations' => Bt03e03Contract::MAX_ITERATIONS, 'eligible_race_count' => 5, 'excluded_race_count' => 0,
                'final_objective' => 1.0, 'previous_objective' => 1.0, 'relative_objective_change' => 0.0,
                'maximum_coefficient_change' => 0.0, 'stationarity_reference_step' => Bt03e03Contract::INITIAL_STEP,
                'current_step' => 1.0, 'optimizer_attempt_count' => 1, 'centering_residual_max' => 0.0, 'prox_gradient_mapping_max' => 0.0];
        }

        return $model;
    }

    public static function make(string $root, int $count = 8): array
    {
        Files::directory($root);
        $input = Files::directory($root.'/input');
        $baseline = Files::directory($root.'/baseline');
        Files::directory($baseline.'/run-01');
        $compare = Files::directory($root.'/compare');
        Files::directory($compare.'/run-01');
        Files::directory($compare.'/run-01/evaluation');
        $c1Artifact = self::model(false);
        $c2Artifact = self::model(true);
        $models = ['C1' => app(C1Loader::class)->restore($c1Artifact), 'C2' => app(C2Loader::class)->restore($c2Artifact)];
        $paths = $seals = $run = $files = $outer = [];
        $metrics = app(Bt03e05MetricEvaluator::class);
        foreach ([2024, 2025] as $year) {
            Files::directory($baseline.'/run-01/C1-fit-'.$year);
            Files::directory($compare.'/run-01/C2-fit-'.$year);
            $paths[$year] = ['input' => $input.'/c1-'.$year.'.jsonl', 'sidecar' => $input.'/stat35-'.$year.'.jsonl',
                'model' => $baseline.'/run-01/C1-fit-'.$year.'/model.json', 'baseline' => $baseline.'/run-01/C1-fit-'.$year.'/predictions.jsonl'];
            JsonlArtifact::json($paths[$year]['model'], $c1Artifact);
            JsonlArtifact::json($compare.'/run-01/C2-fit-'.$year.'/model.json', $c2Artifact);
            $writers = [];
            foreach (['input' => $paths[$year]['input'], 'sidecar' => $paths[$year]['sidecar'], 'C1' => $paths[$year]['baseline'],
                'C2' => $compare.'/run-01/C2-fit-'.$year.'/predictions.jsonl',
                'teacher' => $compare.'/run-01/teacher-'.$year.'.jsonl',
                'contributions' => $compare.'/run-01/evaluation/contributions-'.$year.'.jsonl'] as $key => $path) {
                $writers[$key] = new LineWriter($path);
            }
            $aggregate = $metrics->emptySummary();
            $template = [];
            foreach (Stat35C1ComparisonFixture::races($year, $count) as $i => $race) {
                $writers['input']->append($race);
                $means = [0.0, 1 / 3, 2 / 3, 1.0, null];
                $writers['sidecar']->append(['year' => $year, 'race_id' => $race['race_id'],
                    'entries' => array_map(fn ($e, $m) => ['id' => $e['id'], 'bike' => $e['bike'], 'stat35_mean6' => $m], $race['entries'], $means)]);
                $teacher = $race;
                foreach ($teacher['entries'] as $j => &$entry) {
                    $entry['signals'] = [...$entry['signals'], ...$entry['history'], $means[$j]];
                    unset($entry['history'], $entry['history_status']);
                    $entry['rank'] = $entry['bike'];
                    $entry['status'] = 'FINISHED';
                    if ($i % 8 >= 5 && $entry['bike'] === $i % 8 - 3) {
                        $entry['rank']--;
                        $entry['status'] = 'TIED';
                    }
                }
                unset($entry);
                $writers['teacher']->append($teacher);
                $both = [];
                foreach ($models as $name => $model) {
                    if (! isset($template[$name])) {
                        $binned = $race;
                        foreach ($binned['entries'] as $j => &$entry) {
                            $values = [...$entry['signals'], ...$entry['history'], ...($name === 'C2' ? [$means[$j]] : [])];
                            $entry['bins'] = $model->layout->assign($values, app(EffectBinBuilder::class));
                            unset($entry['signals'], $entry['history'], $entry['history_status']);
                        }
                        unset($entry);
                        $template[$name] = app(Predictor::class)->predict($binned, $model->fit);
                    }
                    $prediction = $template[$name];
                    $prediction['probabilities']['race_id'] = $prediction['decision']['race_id'] = $race['race_id'];
                    foreach ($prediction['probabilities']['entries'] as $j => &$entry) {
                        $entry['id'] = $race['entries'][$j]['id'];
                    }
                    unset($entry);
                    // Known A/B/C/D fixtures; MAP deliberately does not define Primary.
                    $pairs = [[[1, 2, 3], [1, 2, 3]], [[1, 2, 3], [4, 5, 2]], [[4, 5, 2], [1, 2, 3]],
                        [[4, 5, 2], [4, 5, 2]], [[4, 5, 2], [5, 4, 1]]];
                    $primary = $pairs[$i % 5][$name === 'C1' ? 0 : 1];
                    foreach ($primary as $p => $bike) {
                        $prediction['decision']['primary_position_'.($p + 1).'_bike'] = $bike;
                    }
                    $writers[$name]->append($prediction);
                    $both[$name.'-STAT01'] = $metrics->raceComparison($teacher, $prediction['decision']);
                }
                $writers['contributions']->append(['race_id' => $race['race_id'], ...$both]);
                $incremental = $both['C2-STAT01'];
                $incremental['baseline'] = $both['C1-STAT01']['candidate'];
                $metrics->add($aggregate, $incremental);
            }
            foreach ($writers as $writer) {
                $writer->finish();
            }
            $outer['C2-C1'][$year] = $metrics->finish($aggregate);
            foreach ($paths[$year] as $path) {
                $seals[$path] = Files::identity($path);
            }
            foreach (['input', 'sidecar'] as $key) {
                $files[basename($paths[$year][$key])] = $seals[$paths[$year][$key]];
            }
            foreach (['C2-fit-'.$year.'/model.json', 'C2-fit-'.$year.'/predictions.jsonl', 'teacher-'.$year.'.jsonl', 'evaluation/contributions-'.$year.'.jsonl'] as $name) {
                $run[$name] = Files::identity($compare.'/run-01/'.$name);
            }
        }
        JsonlArtifact::json($input.'/manifest.json', ['contract' => Input::plan(), 'status' => 'INPUTS_PREPARED',
            'source' => ['expected_rows' => array_fill_keys([2024, 2025], $count), 'expected_targets' => array_fill_keys([2024, 2025], 5 * $count)], 'files' => $files]);
        JsonlArtifact::json($input.'/COMPLETE.json', Files::identity($input.'/manifest.json'));
        JsonlArtifact::json($compare.'/comparisons.json', ['outer' => $outer]);
        JsonlArtifact::json($compare.'/manifest.json', ['status' => 'COMPLETED_NOT_ADOPTED', 'contract' => Comparison::plan(),
            'source' => ['paths' => $paths, 'seals' => $seals, 'expected_rows' => array_fill_keys([2024, 2025], $count), 'expected_entries' => array_fill_keys([2024, 2025], 5 * $count)],
            'runs' => ['run-01' => $run], 'comparisons' => Files::identity($compare.'/comparisons.json')]);
        JsonlArtifact::json($compare.'/COMPLETE.json', Files::identity($compare.'/manifest.json'));

        return self::bundle($compare, $input, $baseline);
    }

    public static function bundle(string $compare, string $input, string $baseline): array
    {
        $sources = new Sources(Files::identity($compare.'/manifest.json'), hash_file('sha256', $input.'/manifest.json'));

        return ['compare' => $compare, 'input' => $input, 'baseline' => $baseline, 'sources' => $sources,
            'source' => $sources->open($compare, $input, $baseline)];
    }
}
