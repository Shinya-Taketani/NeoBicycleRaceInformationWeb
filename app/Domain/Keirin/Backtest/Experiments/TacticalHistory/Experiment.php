<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use RuntimeException;
use Throwable;

final class Experiment
{
    public function __construct(private readonly Trainer $trainer, private readonly ControlReuse $control, private readonly Dataset $dataset, private readonly Bt03e03OneSeSelector $oneSe) {}

    public function run(string $inputs, string $directory, array $oldE03, string $oldE06Csv, Bt02OutcomeContextSnapshot $snapshot): array
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
        $innerC1 = $innerC0 = $outerPaths = $reuses = [];
        try {
            $event('START', ['inputs' => $inputs]);
            $training = [$inputs.'/inputs-2022.jsonl'];
            $validation = [$inputs.'/inputs-2023.jsonl'];
            $training[] = $validation[0];
            foreach ([2024, 2025] as $year) {
                if ($year === 2025) {
                    $event('C1_INNER_B_FIT_STARTED');
                    $innerC1[2024] = $this->trainer->grid($training, [$directory.'/labels-2024.jsonl'], true, $mkdir($directory.'/C1-inner-B'));
                    $event('C1_INNER_B_FIT_FINISHED');
                    $training[] = $directory.'/labels-2024.jsonl';
                }
                $event('C0_RECONSTRUCTION_STARTED', ['year' => $year]);
                $reuseDirectory = $mkdir($directory.'/C0-reuse-'.$year);
                try {
                    $reuses[$year] = $this->control->predict($year, $training, $inputs.'/inputs-'.$year.'.jsonl', $oldE03['outer_'.$year]['model'], $oldE06Csv, $reuseDirectory);
                    $controlPath = $reuseDirectory.'/predictions.jsonl';
                    $event('C0_REUSE_VERIFIED', ['year' => $year]);
                } catch (ControlReuseException $exception) {
                    JsonlArtifact::json($reuseDirectory.'/ineligible.json', ['status' => 'REUSE_INELIGIBLE', 'reason' => $exception->getMessage()]);
                    $event('C0_REUSE_INELIGIBLE_FIT_REQUIRED', ['year' => $year, 'reason' => $exception->getMessage()]);
                    if (! isset($innerC0[2023])) {
                        $innerC0[2023] = $this->trainer->grid([$inputs.'/inputs-2022.jsonl'], [$inputs.'/inputs-2023.jsonl'], false, $mkdir($directory.'/C0-inner-A'));
                    }
                    if ($year === 2025) {
                        $innerC0[2024] = $this->trainer->grid(array_slice($training, 0, 2), [$directory.'/labels-2024.jsonl'], false, $mkdir($directory.'/C0-inner-B'));
                    }
                    $selection = $this->oneSe->select(array_map(fn ($fold) => $fold['losses'], $innerC0));
                    $fitDirectory = $mkdir($directory.'/C0-fit-'.$year);
                    JsonlArtifact::json($fitDirectory.'/selection.json', $selection);
                    $this->trainer->refit($training, $inputs.'/inputs-'.$year.'.jsonl', false, $selection['lambda'], $fitDirectory);
                    $controlPath = $fitDirectory.'/predictions.jsonl';
                }
                if ($year === 2024) {
                    $event('C1_INNER_A_FIT_STARTED');
                    $innerC1[2023] = $this->trainer->grid([$training[0]], $validation, true, $mkdir($directory.'/C1-inner-A'));
                    $event('C1_INNER_A_FIT_FINISHED');
                }
                $selection = $this->oneSe->select(array_map(fn ($fold) => $fold['losses'], $innerC1));
                $fitDirectory = $mkdir($directory.'/C1-fit-'.$year);
                JsonlArtifact::json($fitDirectory.'/selection.json', $selection);
                $event('C1_OUTER_REFIT_STARTED', ['year' => $year, 'lambda' => $selection['lambda']]);
                $this->trainer->refit($training, $inputs.'/inputs-'.$year.'.jsonl', true, $selection['lambda'], $fitDirectory);
                $event('BOTH_CANDIDATES_SEALED', ['year' => $year]);
                $c1Path = $fitDirectory.'/predictions.jsonl';
                $labels = $directory.'/labels-'.$year.'.jsonl';
                $this->dataset->releaseLabels($year, $inputs.'/inputs-'.$year.'.jsonl', [$controlPath, $c1Path], $snapshot, $labels);
                $event('OUTER_LABELS_RELEASED_AFTER_SEAL', ['year' => $year]);
                $outerPaths[$year] = ['C0' => $controlPath, 'C1' => $c1Path, 'labels' => $labels];
            }
            $progress['status'] = 'MODELS_AND_PREDICTIONS_COMPLETED_NOT_YET_EVALUATED';
            $result = ['status' => $progress['status'], 'outer_paths' => $outerPaths, 'control_reuses' => $reuses];
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
