<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use Generator;
use RuntimeException;

final class ModelLoader
{
    public function __construct(private readonly Dataset $dataset, private readonly EffectBinBuilder $bins, private readonly Predictor $predictor) {}

    public function load(string $path, array $seal): LoadedModel
    {
        Files::verify($path, $seal);
        $model = $this->restore(Files::json($path));
        Files::verify($path, $seal);

        return $model;
    }

    public function published(string $manifest): LoadedModel
    {
        $data = Files::json($manifest);
        Files::same(Contract::plan(), $data['contract'] ?? [], 'saved final model contract');
        if (($data['model_file'] ?? null) !== 'model.json') {
            throw new RuntimeException('Final model path must be model.json.');
        }

        return $this->load(dirname($manifest).'/model.json', $data['model'] ?? []);
    }

    public function restore(array $model): LoadedModel
    {
        if (($model['experiment'] ?? null) !== HistoryAggregator::VERSION
            || ($model['optimizer_version'] ?? null) !== SolverContract::OPTIMIZER_VERSION
            || ($model['model_version'] ?? null) !== SolverContract::MODEL_VERSION
            || ($model['objective_reference_version'] ?? null) !== Bt03e03Contract::OPTIMIZER_VERSION
            || ($model['stat01_anchor_coefficient'] ?? null) !== 1.0
            || ! in_array($model['lambda'] ?? null, Bt03e03Contract::LAMBDA_GRID, true)) {
            throw new RuntimeException('Saved model version/anchor/lambda was invalid.');
        }
        $audit = $model['layout'] ?? [];
        if (array_keys($audit['bins'] ?? []) !== Contract::plan()['features']) {
            throw new RuntimeException('Saved model feature order was invalid.');
        }
        $bins = [];
        foreach ($audit['bins'] as $code => $rows) {
            $bins[$code] = [];
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException('Bin list was invalid.');
            }
            $kind = $rows[0]['kind'] ?? null;
            $categories = [];
            foreach ($rows as $i => $row) {
                if (($row['index'] ?? null) !== $i + 1 || ($row['kind'] ?? null) !== $kind
                    || ! in_array($kind, ['CATEGORY', 'NUMERIC_RANGE'], true)
                    || ! is_int($row['training_support'] ?? null) || $row['training_support'] < 0
                    || array_keys($row) !== ['index', 'kind', 'lower_bound', 'upper_bound', 'category_value', 'training_support']) {
                    throw new RuntimeException('Saved bin identity/support was invalid.');
                }
                if ($kind === 'CATEGORY') {
                    if ($row['lower_bound'] !== null || $row['upper_bound'] !== null || ! is_string($row['category_value'])
                        || in_array($row['category_value'], $categories, true)) {
                        throw new RuntimeException('Saved category bin was invalid.');
                    }
                    $categories[] = $row['category_value'];
                } else {
                    foreach (['lower_bound', 'upper_bound'] as $key) {
                        if ($row[$key] !== null && ! $this->finite($row[$key])) {
                            throw new RuntimeException('Saved numeric boundary was invalid.');
                        }
                    }
                    if ($row['category_value'] !== null || ($i === 0 && $row['lower_bound'] !== null)
                        || ($i > 0 && ($row['lower_bound'] === null || $row['lower_bound'] !== $rows[$i - 1]['upper_bound']))
                        || ($i === count($rows) - 1 ? $row['upper_bound'] !== null : $row['upper_bound'] === null)
                        || ($row['lower_bound'] !== null && $row['upper_bound'] !== null && $row['lower_bound'] >= $row['upper_bound'])) {
                        throw new RuntimeException('Saved numeric bin coverage/order was invalid.');
                    }
                }
                $bins[$code][] = new EffectBinDto($row['index'], $kind, $row['lower_bound'], $row['upper_bound'], $row['category_value'], $row['training_support']);
            }
        }
        $layout = new Layout($bins);
        $rebuilt = ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(),
            'active_group_count_G' => count($layout->groups()), 'numeric_edge_count' => count($layout->smoothEdges()),
            'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(), 'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()];
        Files::same($rebuilt, $audit, 'saved layout reconstruction');
        foreach (['position_coefficients', 'weighted_center_means', 'objectives', 'iterations', 'eligible_races', 'excluded_races', 'optimizer_diagnostics'] as $key) {
            if (array_keys($model[$key] ?? []) !== Bt03e03Contract::POSITIONS) {
                throw new RuntimeException('Saved position keys were invalid: '.$key);
            }
        }
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $coefficients = $model['position_coefficients'][$position];
            if (! is_array($coefficients) || ! array_is_list($coefficients) || count($coefficients) !== $layout->size()
                || count(array_filter($coefficients, $this->finite(...))) !== count($coefficients)) {
                throw new RuntimeException('Saved coefficient vector was invalid.');
            }
            $means = $layout->weightedMeans($coefficients);
            Files::same($means, $model['weighted_center_means'][$position], 'saved centering audit');
            $diag = $model['optimizer_diagnostics'][$position];
            if (max(array_map('abs', $means)) > Bt03e03Contract::CONVERGENCE_TOLERANCE
                || ($diag['status'] ?? null) !== 'CONVERGED' || ($diag['optimizer_version'] ?? null) !== SolverContract::OPTIMIZER_VERSION
                || ! $this->finite($model['objectives'][$position])
                || ! is_int($model['iterations'][$position]) || $model['iterations'][$position] < 1 || $model['iterations'][$position] > 200
                || ! is_int($model['eligible_races'][$position]) || $model['eligible_races'][$position] < 1
                || ! is_int($model['excluded_races'][$position]) || $model['excluded_races'][$position] < 0) {
                throw new RuntimeException('Saved model centering/convergence was invalid.');
            }
            foreach (['prox_gradient_mapping_max', 'centering_residual_max'] as $key) {
                if (! $this->finite($diag[$key] ?? null) || $diag[$key] < 0 || $diag[$key] > Bt03e03Contract::CONVERGENCE_TOLERANCE) {
                    throw new RuntimeException('Saved optimality residual was invalid.');
                }
            }
        }

        return new LoadedModel($layout, new Bt03e03FitResultDto($model['lambda'], $model['position_coefficients'], $model['objectives'],
            $model['iterations'], $model['eligible_races'], $model['excluded_races'], $model['optimizer_diagnostics']), $model);
    }

    public function predictions(string $input, LoadedModel $model): Generator
    {
        $seal = Files::json($input.'.manifest.json');
        Files::verify($input, $seal);
        $raw = function () use ($input): Generator {
            foreach (JsonlArtifact::read($input) as $race) {
                if (array_keys($race) !== ['year', 'race_id', 'entries'] || ! in_array($race['year'], [2022, 2023, 2024, 2025], true)
                    || ! is_int($race['race_id']) || $race['race_id'] < 1 || ! is_array($race['entries'])
                    || ! array_is_list($race['entries']) || count($race['entries']) < 5 || count($race['entries']) > 9) {
                    throw new RuntimeException('Prediction race fields/year were invalid.');
                }
                $bikes = [];
                foreach ($race['entries'] as $entry) {
                    if (array_keys($entry) !== ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'signals', 'history', 'history_status']
                        || ! is_int($entry['bike']) || $entry['bike'] < 1 || $entry['bike'] > 9 || in_array($entry['bike'], $bikes, true)
                        || ! $this->finite($entry['anchor']) || ! $this->finite($entry['raw'])
                        || ! is_array($entry['signals']) || ! array_is_list($entry['signals']) || count($entry['signals']) !== 12
                        || ! is_array($entry['history']) || ! array_is_list($entry['history']) || count($entry['history']) !== 4) {
                        throw new RuntimeException('Prediction entry fields/outcomes were invalid.');
                    }
                    foreach ($entry['signals'] as $v) {
                        if ($v !== null && ! is_string($v) && ! $this->finite($v)) {
                            throw new RuntimeException('Prediction feature was non-finite.');
                        }
                    }
                    foreach ($entry['history'] as $v) {
                        if ($v !== null && (! is_int($v) || $v < 0)) {
                            throw new RuntimeException('Prediction history count was invalid.');
                        }
                    }
                    $bikes[] = $entry['bike'];
                }
            }
            yield from $this->dataset->raw([$input], true, true);
        };
        foreach ($this->dataset->binned($raw, $model->layout, $this->bins) as $race) {
            yield $this->predictor->predict($race, $model->fit);
        }
        Files::verify($input, $seal);
    }

    private function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite($value);
    }
}
