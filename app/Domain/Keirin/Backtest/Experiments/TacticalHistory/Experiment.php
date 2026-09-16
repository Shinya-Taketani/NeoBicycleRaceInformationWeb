<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use RuntimeException;
use Throwable;

final class Experiment
{
    public function __construct(private readonly Trainer $trainer, private readonly Dataset $dataset, private readonly Bt03e03OneSeSelector $oneSe) {}

    public function run(string $inputs, string $directory, Bt02OutcomeContextSnapshot $snapshot): array
    {
        if (file_exists($directory) || ! mkdir($directory, 0755)) {
            throw new RuntimeException('Experiment run directory must be new.');
        }
        $progress = ['status' => 'RUNNING', 'started_at' => gmdate('c'), 'pid' => getmypid(), 'completed_steps' => []];
        $event = function (string $step, array $details = []) use ($directory, &$progress): void {
            $progress['completed_steps'][] = ['step' => $step, 'at' => gmdate('c'), ...$details];
            $temporary = $directory.'/run-state-next.json';
            JsonlArtifact::json($temporary, $progress);
            if (! rename($temporary, $directory.'/run-state.json')) {
                throw new RuntimeException('Could not checkpoint run state.');
            }
            echo json_encode(['step' => $step, ...$details])."\n";
        };
        $mkdir = static function (string $path): string {
            if (! mkdir($path, 0755)) {
                throw new RuntimeException('Could not create model directory.');
            }

            return $path;
        };
        $inner = $outerPaths = [];
        try {
            $event('START', ['inputs' => $inputs]);
            $training = [$inputs.'/inputs-2022.jsonl', $inputs.'/inputs-2023.jsonl'];
            foreach ([2024, 2025] as $year) {
                $paths = [];
                foreach (['C0' => false, 'C1' => true] as $candidate => $history) {
                    $fold = $year === 2024 ? 'A' : 'B';
                    $event($candidate.'_INNER_'.$fold.'_FIT_STARTED');
                    $inner[$candidate][$year - 1] = $this->trainer->grid(
                        $year === 2024 ? [$training[0]] : $training,
                        $year === 2024 ? [$training[1]] : [$directory.'/labels-2024.jsonl'],
                        $history,
                        $mkdir($directory.'/'.$candidate.'-inner-'.$fold),
                    );
                    $event($candidate.'_INNER_'.$fold.'_FIT_FINISHED');
                    $selection = $this->oneSe->select(array_map(fn ($fold) => $fold['losses'], $inner[$candidate]));
                    $fitDirectory = $mkdir($directory.'/'.$candidate.'-fit-'.$year);
                    JsonlArtifact::json($fitDirectory.'/selection.json', $selection);
                    $event($candidate.'_OUTER_REFIT_STARTED', ['year' => $year, 'lambda' => $selection['lambda']]);
                    $this->trainer->refit($year === 2024 ? $training : [...$training, $directory.'/labels-2024.jsonl'], $inputs.'/inputs-'.$year.'.jsonl', $history, $selection['lambda'], $fitDirectory);
                    $paths[$candidate] = $fitDirectory.'/predictions.jsonl';
                }
                $event('BOTH_CANDIDATES_SEALED', ['year' => $year]);
                $labels = $directory.'/labels-'.$year.'.jsonl';
                $this->dataset->releaseLabels($year, $inputs.'/inputs-'.$year.'.jsonl', array_values($paths), $snapshot, $labels);
                $event('OUTER_LABELS_RELEASED_AFTER_SEAL', ['year' => $year]);
                $outerPaths[$year] = [...$paths, 'labels' => $labels];
            }
            $progress['status'] = 'MODELS_AND_PREDICTIONS_COMPLETED_NOT_YET_EVALUATED';
            $result = ['status' => $progress['status'], 'outer_paths' => $outerPaths, 'control_reuses' => [],
                'optimizer_version' => SolverContract::OPTIMIZER_VERSION, 'model_version' => SolverContract::MODEL_VERSION];
            JsonlArtifact::json($directory.'/model-run.json', $result);
            $event('MODELS_AND_PREDICTIONS_FINISHED');

            return $result;
        } catch (Throwable $exception) {
            $progress['status'] = 'FAILED';
            $event('FAILED', ['error_class' => $exception::class, 'message' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
