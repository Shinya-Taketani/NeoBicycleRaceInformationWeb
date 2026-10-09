<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e03OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Dataset;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Layout;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e03ValidationLossSpool as Spool;
use RuntimeException;

final class PathTrainer
{
    public function __construct(private readonly Optimizer $optimizer, private readonly Trainer $trainer,
        private readonly LayoutBuilder $layouts, private readonly Dataset $dataset, private readonly EffectBinBuilder $bins) {}

    public static function prefix(array $eligible): array
    {
        $keys = array_map(Spool::lambdaKey(...), Bt03e03Contract::LAMBDA_GRID);
        if ($eligible === [] || array_diff($eligible, $keys) !== []) {
            throw new RuntimeException('Invalid reused common candidates.');
        }
        $stop = min(array_map('floatval', $eligible));
        $order = [];
        foreach (Bt03e03Contract::FIT_EXECUTION_ORDER as $lambda) {
            $order[] = $lambda;
            if ($lambda === $stop) {
                break;
            }
        }

        return $order;
    }

    public function fit(callable $training, Layout $layout, array $eligible, ?callable $audit = null): array
    {
        $order = self::prefix($eligible);
        $models = $statuses = $warm = [];
        $warmLambda = null;
        foreach ($order as $lambda) {
            $key = Spool::lambdaKey($lambda);
            try {
                $fit = $this->optimizer->fit($training, $layout, $lambda, $warm);
                $models[$key] = $fit;
                $statuses[$key] = ['lambda' => $lambda, 'status' => 'CONVERGED',
                    'warm_start_from_lambda' => $warmLambda, 'positions' => $fit->diagnostics];
                $warm = $fit->coefficients;
                $warmLambda = $lambda;
            } catch (Bt03e03OptimizerNonConvergenceException $e) {
                $statuses[$key] = ['lambda' => $lambda, 'status' => 'NUMERICALLY_NON_CONVERGED',
                    'warm_start_from_lambda' => $warmLambda, 'failed_position' => $e->diagnostics['position'],
                    'positions' => [$e->diagnostics['position'] => $e->diagnostics]];
            }
            if ($audit !== null) {
                $audit($statuses[$key]);
            }
        }
        $fits = $canonical = [];
        foreach (Bt03e03Contract::LAMBDA_GRID as $lambda) {
            $key = Spool::lambdaKey($lambda);
            $canonical[$key] = $statuses[$key] ?? ['lambda' => $lambda, 'status' => Contract::OMITTED,
                'reason' => 'Not eligible in both reused folds; weaker than their minimum eligible lambda.'];
            if (isset($models[$key])) {
                $fits[$key] = $models[$key];
            }
        }

        return ['fits' => $fits, 'candidate_statuses' => $canonical, 'fit_order' => $order];
    }

    public function train(callable $training, array $eligible, string $directory): array
    {
        $layout = $this->layouts->build($training);
        Jsonl::json($directory.'/layout.json', $this->trainer->layoutAudit($layout));
        Jsonl::write($directory.'/training.jsonl', $this->dataset->binned($training, $layout, $this->bins));
        $passes = 0;
        $source = static function () use ($directory, &$passes): \Generator {
            $passes++;
            if ($passes === 1 || $passes % 10 === 0) {
                echo json_encode(['phase' => 'OOF3_OPTIMIZER_PASS', 'pass' => $passes, 'time' => gmdate('c'), 'peak_bytes' => memory_get_peak_usage(true)])."\n";
            }
            yield from Jsonl::read($directory.'/training.jsonl');
        };
        $path = $this->fit($source, $layout, $eligible,
            static function ($status) use ($directory): void {
                Jsonl::json($directory.'/candidate-'.Spool::lambdaKey($status['lambda']).'.json', $status);
                echo json_encode(['phase' => 'OOF3_CANDIDATE', 'lambda' => $status['lambda'], 'status' => $status['status']])."\n";
            });
        Jsonl::json($directory.'/path.json', ['fit_order' => $path['fit_order'], 'candidate_statuses' => $path['candidate_statuses'],
            'models' => array_map(fn ($fit) => $this->trainer->model($layout, $fit), $path['fits'])]);

        return $path + ['layout' => $layout];
    }
}
