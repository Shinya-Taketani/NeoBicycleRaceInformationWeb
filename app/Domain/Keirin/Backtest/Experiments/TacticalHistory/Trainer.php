<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e03ValidationLossSpool;

final class Trainer
{
    public function __construct(
        private readonly LayoutBuilder $layouts,
        private readonly Dataset $dataset,
        private readonly EffectBinBuilder $bins,
        private readonly Optimizer $optimizer,
        private readonly Objective $objective,
        private readonly Predictor $predictor,
    ) {}

    public function grid(array $training, array $validation, bool $history, string $directory): array
    {
        $raw = fn () => $this->dataset->raw($training, $history);
        $layout = $this->layouts->build($raw, $history);
        JsonlArtifact::json($directory.'/layout.json', $this->layoutAudit($layout));
        JsonlArtifact::write($directory.'/training.jsonl', $this->dataset->binned($raw, $layout, $this->bins));
        $path = $this->auditedOptimizer($directory)->fitPath($this->progressSource($directory.'/training.jsonl'), $layout);
        JsonlArtifact::json($directory.'/path.json', ['fit_order' => $path['fit_order'], 'candidate_statuses' => $path['candidate_statuses'],
            'models' => array_map(fn ($fit) => $this->model($layout, $fit), $path['fits'])]);
        $losses = new Bt03e03ValidationLossSpool($directory.'/losses-working.bin', array_keys($path['fits']));
        $validationRaw = fn () => $this->dataset->raw($validation, $history);
        $rows = (function () use ($validationRaw, $layout, $path, $losses): \Generator {
            foreach ($this->dataset->binned($validationRaw, $layout, $this->bins) as $race) {
                $values = [];
                foreach ($path['fits'] as $key => $fit) {
                    foreach (Bt03e03Contract::POSITIONS as $position) {
                        $values[$key][$position] = $this->objective->raceLoss($race, $layout, $fit->coefficients[$position], $position);
                    }
                }
                $losses->append($values);
                yield ['race_id' => $race['race_id'], 'losses' => $values];
            }
        })();
        JsonlArtifact::write($directory.'/validation-losses.jsonl', $rows);
        $losses->seal();

        return ['losses' => $losses, 'path' => $path, 'layout' => $layout];
    }

    public function refit(array $training, string $predictionInput, bool $history, float $lambda, string $directory): array
    {
        $raw = fn () => $this->dataset->raw($training, $history);
        $layout = $this->layouts->build($raw, $history);
        JsonlArtifact::json($directory.'/layout.json', $this->layoutAudit($layout));
        JsonlArtifact::write($directory.'/training.jsonl', $this->dataset->binned($raw, $layout, $this->bins));
        $path = $this->auditedOptimizer($directory)->fitSelectedViaPath($this->progressSource($directory.'/training.jsonl'), $layout, $lambda);
        $model = $this->model($layout, $path['fit']);
        JsonlArtifact::json($directory.'/model.json', $model);
        JsonlArtifact::json($directory.'/refit-path.json', ['fit_order' => $path['fit_order'], 'candidate_statuses' => $path['candidate_statuses']]);
        $rows = (function () use ($predictionInput, $history, $layout, $path): \Generator {
            $raw = fn () => $this->dataset->raw([$predictionInput], $history, true);
            foreach ($this->dataset->binned($raw, $layout, $this->bins) as $race) {
                yield $this->predictor->predict($race, $path['fit']);
            }
        })();
        $manifest = JsonlArtifact::write($directory.'/predictions.jsonl', $rows);

        return ['model' => $model, 'predictions' => $manifest];
    }

    private function progressSource(string $path): callable
    {
        $passes = 0;

        return static function () use ($path, &$passes): \Generator {
            $passes++;
            if ($passes === 1 || $passes % 10 === 0) {
                echo json_encode(['phase' => 'OPTIMIZER_PASS', 'source' => $path, 'pass' => $passes, 'time' => gmdate('c'), 'peak_bytes' => memory_get_peak_usage(true)])."\n";
            }
            yield from JsonlArtifact::read($path);
        };
    }

    private function auditedOptimizer(string $directory): Optimizer
    {
        return $this->optimizer->withAudit(static function (array $candidate) use ($directory): void {
            JsonlArtifact::json($directory.'/candidate-'.sprintf('%.17g', $candidate['lambda']).'.json', $candidate);
            echo json_encode(['phase' => 'CANDIDATE_FINISHED', 'lambda' => $candidate['lambda'], 'status' => $candidate['status']])."\n";
        });
    }

    private function layoutAudit(Layout $layout): array
    {
        return ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(),
            'active_group_count_G' => count($layout->groups()), 'numeric_edge_count' => count($layout->smoothEdges()),
            'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(), 'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()];
    }

    private function model(Layout $layout, Bt03e03FitResultDto $fit): array
    {
        return ['experiment' => HistoryAggregator::VERSION, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'model_version' => SolverContract::MODEL_VERSION, 'objective_reference_version' => Bt03e03Contract::OPTIMIZER_VERSION,
            'lambda' => $fit->lambda, 'stat01_anchor_coefficient' => 1.0, 'layout' => $this->layoutAudit($layout),
            'position_coefficients' => $fit->coefficients, 'weighted_center_means' => array_map(fn ($c) => $layout->weightedMeans($c), $fit->coefficients),
            'objectives' => $fit->objectives, 'iterations' => $fit->iterations, 'eligible_races' => $fit->eligibleRaceCounts,
            'excluded_races' => $fit->excludedRaceCounts, 'optimizer_diagnostics' => $fit->diagnostics];
    }
}
