<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Signal;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;

trait GrowthTrendCalibrationFixture
{
    private function raw(int $year = 2024): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = ['id' => $year * 10 + $bike, 'bike' => $bike, 'raw' => 100.0,
                'stat01_rank' => 1, 'anchor' => 0.0, 'anchor_status' => 'ZERO_VARIANCE', 'signals' => array_fill(0, 12, 0),
                'history' => [0, 0, 0, 0], 'history_status' => 'AVAILABLE'];
        }

        return ['year' => $year, 'race_id' => $year, 'entries' => $entries];
    }

    private function trend(int $year = 2024): array
    {
        $out = [];
        foreach ([-3.0, -1.0, 0.0, 3.0, null] as $i => $raw) {
            $out[] = [
                'year' => $year, 'race_id' => $year, 'entry_id' => $year * 10 + $i + 1, 'player_id' => $i + 1, 'bike' => $i + 1,
                'first_score_observation_in_target_meeting' => true, 'target_boundary_partial_time_order' => $raw === null,
                'candidates' => [Contract::SIGNAL => ['raw' => $raw, 'status' => $raw === null ? 'PARTIAL_TIME_ORDER' : 'VALID'],
                    'OTHER_SIGNAL' => ['raw' => 999, 'status' => 'VALID']], 'unneeded_outcome_diagnostic' => 'MUST_NOT_PROJECT'];
        }

        return $out;
    }

    private function prepared(int $year = 2024): array
    {
        $model = app(ModelLoader::class)->restore($this->model());
        $race = $this->raw($year);
        foreach ($race['entries'] as &$entry) {
            $entry['bins'] = $model->layout->assign([...$entry['signals'], ...$entry['history']], app(EffectBinBuilder::class));
            unset($entry['signals'], $entry['history'], $entry['history_status']);
        }
        unset($entry);

        return [['race' => $race, 'growth' => iterator_to_array(Signal::projection($this->trend($year)))], $model];
    }

    private function model(): array
    {
        // An independently constructed synthetic saved model. No fitting/selection is run.
        $bins = [];
        foreach (FinalContract::plan()['features'] as $feature) {
            $bins[$feature] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 5), new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, null, null, 5)];
        }
        $layout = new Layout($bins);
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $diagnostics = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $diagnostics[$position] = ['optimizer_version' => SolverContract::OPTIMIZER_VERSION, 'status' => 'CONVERGED',
                'lambda' => 0.1, 'position' => $position, 'iteration' => 1, 'accepted_update_count' => 1, 'max_iterations' => 200,
                'optimizer_attempt_count' => 1, 'eligible_race_count' => 1, 'excluded_race_count' => 0,
                'final_objective' => 1.0, 'previous_objective' => 1.0, 'relative_objective_change' => 0.0, 'maximum_coefficient_change' => 0.0,
                'current_step' => 1.0, 'prox_gradient_mapping_max' => 0.0, 'stationarity_reference_step' => 1.0, 'centering_residual_max' => 0.0];
        }

        return ['experiment' => HistoryAggregator::VERSION, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'model_version' => SolverContract::MODEL_VERSION, 'objective_reference_version' => Bt03e03Contract::OPTIMIZER_VERSION,
            'stat01_anchor_coefficient' => 1.0, 'lambda' => 0.1,
            'layout' => ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(),
                'active_group_count_G' => count($layout->groups()), 'numeric_edge_count' => count($layout->smoothEdges()),
                'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(), 'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()],
            'position_coefficients' => array_fill_keys(Bt03e03Contract::POSITIONS, $coefficients),
            'weighted_center_means' => array_fill_keys(Bt03e03Contract::POSITIONS, $layout->weightedMeans($coefficients)),
            'objectives' => array_fill_keys(Bt03e03Contract::POSITIONS, 1.0), 'iterations' => array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            'eligible_races' => array_fill_keys(Bt03e03Contract::POSITIONS, 1), 'excluded_races' => array_fill_keys(Bt03e03Contract::POSITIONS, 0), 'optimizer_diagnostics' => $diagnostics];
    }
}
