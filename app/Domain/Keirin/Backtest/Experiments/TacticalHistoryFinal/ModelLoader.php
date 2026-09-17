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
use PDO;
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
                || ! is_int($model['iterations'][$position]) || $model['iterations'][$position] < 1 || $model['iterations'][$position] > Bt03e03Contract::MAX_ITERATIONS
                || ! is_int($model['eligible_races'][$position]) || $model['eligible_races'][$position] < 1
                || ! is_int($model['excluded_races'][$position]) || $model['excluded_races'][$position] < 0) {
                throw new RuntimeException('Saved model centering/convergence was invalid.');
            }
            foreach (['prox_gradient_mapping_max', 'centering_residual_max'] as $key) {
                if (! $this->finite($diag[$key] ?? null) || $diag[$key] < 0 || $diag[$key] > Bt03e03Contract::CONVERGENCE_TOLERANCE) {
                    throw new RuntimeException('Saved optimality residual was invalid.');
                }
            }
            $this->validateDiagnostics($model, $position, max(array_map('abs', $means)));
        }

        return new LoadedModel($layout, new Bt03e03FitResultDto($model['lambda'], $model['position_coefficients'], $model['objectives'],
            $model['iterations'], $model['eligible_races'], $model['excluded_races'], $model['optimizer_diagnostics']), $model);
    }

    public function predictions(string $input, LoadedModel $model): Generator
    {
        $seal = Files::json($input.'.manifest.json');
        Files::verify($input, $seal);
        $raw = function () use ($input): Generator {
            $this->validateInput($input);
            yield from $this->dataset->raw([$input], true, true);
        };
        foreach ($this->dataset->binned($raw, $model->layout, $this->bins) as $race) {
            yield $this->predictor->predict($race, $model->fit);
        }
        Files::verify($input, $seal);
    }

    private function validateInput(string $input): void
    {
        // An empty SQLite filename is a private, automatically removed disk database.
        // Keep identity memory bounded without imposing any ordering on the input.
        $identities = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $identities->exec('PRAGMA temp_store=FILE');
        $identities->exec('PRAGMA cache_size=-1024');
        $identities->exec('CREATE TABLE seen (id INTEGER PRIMARY KEY)');
        $insert = $identities->prepare('INSERT OR IGNORE INTO seen (id) VALUES (?)');
        $identities->beginTransaction();
        try {
            foreach (JsonlArtifact::read($input) as $race) {
                if (! $this->hasKeys($race, ['year', 'race_id', 'entries']) || ! in_array($race['year'], [2022, 2023, 2024, 2025], true)
                    || ! is_int($race['race_id']) || $race['race_id'] < 1 || ! is_array($race['entries'])
                    || ! array_is_list($race['entries']) || count($race['entries']) < 5 || count($race['entries']) > 9) {
                    throw new RuntimeException('Prediction race fields/year were invalid.');
                }
                $insert->execute([$race['race_id']]);
                if ($insert->rowCount() !== 1) {
                    throw new RuntimeException('Prediction input contained a duplicate race ID.');
                }
                $bikes = $entries = [];
                foreach ($race['entries'] as $entry) {
                    if (! $this->hasKeys($entry, ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'signals', 'history', 'history_status'])
                        || ! is_int($entry['id']) || $entry['id'] < 1 || in_array($entry['id'], $entries, true)
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
                    $unavailable = ['MISSING_TARGET_METADATA', 'LEFT_TRUNCATED', 'INVALID_HISTORY',
                        'PARTIAL_HISTORY', 'MISSING_METHOD_HISTORY', 'NO_HISTORY'];
                    if ($entry['history_status'] === 'AVAILABLE') {
                        $consistent = ! in_array(null, $entry['history'], true);
                    } else {
                        $consistent = in_array($entry['history_status'], $unavailable, true)
                            && $entry['history'] === [null, null, null, null];
                    }
                    if (! $consistent) {
                        throw new RuntimeException('Prediction history status/counts disagreed.');
                    }
                    $bikes[] = $entry['bike'];
                    $entries[] = $entry['id'];
                }
                $this->validateAnchors($race['entries']);
            }
        } finally {
            $identities->rollBack();
        }
    }

    private function validateAnchors(array $entries): void
    {
        // Reproduce InputBuilder's definition without modifying values or entrant order.
        $raws = array_map(static fn (array $entry): float => (float) $entry['raw'], $entries);
        $mean = array_sum($raws) / count($raws);
        $sd = sqrt(array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $raws)) / count($raws));
        if (! is_finite($mean) || ! is_finite($sd)) {
            throw new RuntimeException('Prediction anchor statistics were non-finite.');
        }
        foreach ($entries as $entry) {
            $status = $sd > 0.0 ? 'AVAILABLE' : 'ZERO_VARIANCE';
            $anchor = $sd > 0.0 ? ((float) $entry['raw'] - $mean) / $sd : 0.0;
            if ($entry['anchor_status'] !== $status || (float) $entry['anchor'] !== $anchor) {
                throw new RuntimeException('Prediction anchor status/value disagreed with race scores.');
            }
        }
    }

    private function hasKeys(mixed $value, array $keys): bool
    {
        return is_array($value) && count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }

    private function validateDiagnostics(array $model, string $position, float $centeringResidual): void
    {
        $diag = $model['optimizer_diagnostics'][$position];
        foreach (['lambda' => $model['lambda'], 'position' => $position,
            'iteration' => $model['iterations'][$position], 'accepted_update_count' => $model['iterations'][$position],
            'max_iterations' => Bt03e03Contract::MAX_ITERATIONS, 'eligible_race_count' => $model['eligible_races'][$position],
            'excluded_race_count' => $model['excluded_races'][$position]] as $key => $expected) {
            if (($diag[$key] ?? null) !== $expected) {
                throw new RuntimeException('Saved diagnostics disagreed with model: '.$key);
            }
        }
        foreach (['final_objective', 'previous_objective', 'relative_objective_change', 'maximum_coefficient_change',
            'stationarity_reference_step', 'current_step'] as $key) {
            if (! $this->finite($diag[$key] ?? null) || $diag[$key] < 0.0) {
                throw new RuntimeException('Saved diagnostic value was invalid: '.$key);
            }
        }
        $relative = abs($diag['previous_objective'] - $diag['final_objective']) / max(1.0, abs($diag['previous_objective']));
        if ((float) $diag['final_objective'] !== (float) $model['objectives'][$position]
            || (float) $diag['relative_objective_change'] !== $relative
            || $relative > Bt03e03Contract::OBJECTIVE_TOLERANCE
            || $diag['maximum_coefficient_change'] > Bt03e03Contract::CONVERGENCE_TOLERANCE
            || (float) $diag['stationarity_reference_step'] !== Bt03e03Contract::INITIAL_STEP
            || (float) $diag['centering_residual_max'] !== $centeringResidual
            || $diag['current_step'] <= 0.0 || $diag['current_step'] > Bt03e03Contract::INITIAL_STEP
            || ! is_int($diag['optimizer_attempt_count'] ?? null)
            || $diag['optimizer_attempt_count'] < $model['iterations'][$position]
            || $diag['optimizer_attempt_count'] > 2 * $model['iterations'][$position]) {
            throw new RuntimeException('Saved diagnostics contradicted model/convergence conditions.');
        }
    }

    private function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite($value);
    }
}
