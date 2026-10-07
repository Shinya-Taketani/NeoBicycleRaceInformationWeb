<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1PositionLambda;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e03OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

final class Trainer
{
    public function __construct(private readonly LayoutBuilder $layouts, private readonly Dataset $dataset,
        private readonly EffectBinBuilder $bins, private readonly Optimizer $optimizer,
        private readonly Objective $objective, private readonly Predictor $predictor) {}

    public function pool(callable $training, array $teachers, string $name, string $directory, ?array $stop = null, array $originalSource = []): array
    {
        $years = Contract::plan()['pools'][$name] ?? throw new RuntimeException('Unknown training pool.');
        if (array_keys($teachers) !== $years || ($stop !== null && array_keys($stop) !== Bt03e03Contract::POSITIONS)) {
            throw new RuntimeException('Pool teacher years/selected positions disagreed.');
        }
        foreach ($stop ?? [] as $lambda) {
            if (! in_array($lambda, Bt03e03Contract::LAMBDA_GRID, true)) {
                throw new RuntimeException('Selected position lambda outside fixed grid.');
            }
        }
        $inputs = array_map(Files::identity(...), $teachers);
        $layout = $this->layouts->build($training, true);
        JsonlArtifact::json($directory.'/layout.json', $this->layoutAudit($layout));
        JsonlArtifact::write($directory.'/training.jsonl', $this->dataset->binned($training, $layout, $this->bins));
        if ($originalSource === [] && ! app()->environment('testing')) {
            throw new RuntimeException('Original fixed pool source seals required.');
        }
        $poolContract = ['pool' => $name, 'years' => $years, 'teacher_seals' => $inputs, 'original_source' => $originalSource,
            'source_code' => Contract::code(), 'layout' => Files::identity($directory.'/layout.json'),
            'training' => Files::identity($directory.'/training.jsonl'), 'path_version' => Contract::PATH_VERSION];
        JsonlArtifact::json($directory.'/pool-contract.json', $poolContract);
        $fits = $statuses = $orders = [];
        $source = $this->progressSource($directory.'/training.jsonl');
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $warm = null;
            $warmLambda = null;
            foreach (Bt03e03Contract::FIT_EXECUTION_ORDER as $lambda) {
                $key = LossSpool::key($lambda);
                $orders[$position][] = $lambda;
                $status = ['position' => $position, 'lambda' => $lambda, 'warm_start_from_lambda' => $warmLambda];
                try {
                    $fit = $this->optimizer->fitPosition($source, $layout, $lambda, $position, $warm);
                    $status += ['status' => 'CONVERGED', 'diagnostics' => $fit['diagnostics']];
                    $fits[$position][$key] = $fit;
                    $warm = $fit['coefficients'];
                    $warmLambda = $lambda;
                } catch (Bt03e03OptimizerNonConvergenceException $e) {
                    $fit = null;
                    $status += ['status' => 'NUMERICALLY_NON_CONVERGED', 'diagnostics' => $e->diagnostics];
                }
                $statuses[$position][$key] = $status;
                JsonlArtifact::json($directory.'/'.$position.'-candidate-'.$key.'.json', ['trial' => $status, 'fit' => $fit]);
                echo json_encode(['phase' => 'POSITION_CANDIDATE_FINISHED', 'pool' => $name, ...$status])."\n";
                if ($stop !== null && $lambda === $stop[$position]) {
                    if ($fit === null) {
                        throw new RuntimeException('Selected position lambda did not converge; fallback forbidden.');
                    }
                    break;
                }
            }
            if ($stop !== null && ! isset($fits[$position][LossSpool::key($stop[$position])])) {
                throw new RuntimeException('Selected position lambda outside fixed path.');
            }
            $ordered = [];
            foreach (Bt03e03Contract::LAMBDA_GRID as $lambda) {
                $key = LossSpool::key($lambda);
                if (isset($fits[$position][$key])) {
                    $ordered[$key] = $fits[$position][$key];
                }
            }
            $fits[$position] = $ordered;
        }
        foreach ($teachers as $year => $path) {
            Files::verify($path, $inputs[$year]);
        }
        Files::verify($directory.'/layout.json', $poolContract['layout']);
        Files::verify($directory.'/training.jsonl', $poolContract['training']);
        Files::same($poolContract['source_code'], Contract::code(), 'pool code start/end');
        JsonlArtifact::json($directory.'/path.json', ['version' => Contract::PATH_VERSION, 'fit_order' => $orders,
            'candidate_statuses' => $statuses, 'attempt_count' => array_sum(array_map('count', $orders))]);
        $files = ['pool-contract.json' => Files::identity($directory.'/pool-contract.json'), 'path.json' => Files::identity($directory.'/path.json')];
        foreach (glob($directory.'/POSITION_*-candidate-*.json') as $path) {
            $files[basename($path)] = Files::identity($path);
        }
        ksort($files);
        JsonlArtifact::json($directory.'/pool-sealed.json', $files);
        foreach (Bt03e03Contract::POSITIONS as $p) {
            if ($fits[$p] === []) {
                throw new RuntimeException('No converged candidate for '.$p.'; NOT_EVALUATED.');
            }
        }

        return ['name' => $name, 'directory' => $directory, 'layout' => $layout, 'fits' => $fits, 'statuses' => $statuses,
            'contract' => $poolContract, 'files' => $files];
    }

    public function validation(array $pool, callable $validation, string $directory): array
    {
        $this->verifyPool($pool);
        $spools = [];
        foreach (Bt03e03Contract::POSITIONS as $p) {
            $spools[$p] = new LossSpool($directory.'/'.$p.'-working.bin', $p, array_keys($pool['fits'][$p]));
        }
        $rows = (function () use ($pool, $validation, $spools): \Generator {
            foreach ($this->dataset->binned($validation, $pool['layout'], $this->bins) as $race) {
                $values = [];
                foreach (Bt03e03Contract::POSITIONS as $p) {
                    foreach ($pool['fits'][$p] as $key => $fit) {
                        $values[$p][$key] = $this->objective->raceLoss($race, $pool['layout'], $fit['coefficients'], $p);
                    }
                    $spools[$p]->append($values[$p]);
                }
                yield ['race_id' => $race['race_id'], 'losses_by_position' => $values];
            }
        })();
        JsonlArtifact::write($directory.'/validation-losses.jsonl', $rows);
        foreach ($spools as $spool) {
            $spool->seal();
        }
        JsonlArtifact::json($directory.'/validation-audit.json', ['pool' => $pool['name'], 'pool_seal' => $pool['files'],
            'new_fit_count' => 0, 'positions' => array_map(fn ($s) => $s->audit(), $spools)]);
        $this->verifyPool($pool);

        return $spools;
    }

    public function predict(array $pool, array $selections, callable $input, string $directory): void
    {
        $this->verifyPool($pool);
        if (array_keys($selections) !== Bt03e03Contract::POSITIONS) {
            throw new RuntimeException('Missing selected position.');
        }
        $lambda = $coefficients = $objectives = $iterations = $eligible = $excluded = $diagnostics = [];
        foreach (Bt03e03Contract::POSITIONS as $p) {
            Selector::validate($selections[$p], $p);
            $lambda[$p] = $selections[$p]['selected_lambda'];
            $fit = $pool['fits'][$p][LossSpool::key($lambda[$p])] ?? throw new RuntimeException('Selected pooled position did not converge; fallback forbidden.');
            $coefficients[$p] = $fit['coefficients'];
            $objectives[$p] = $fit['objective'];
            $iterations[$p] = $fit['iterations'];
            $eligible[$p] = $fit['eligible_races'];
            $excluded[$p] = $fit['excluded_races'];
            $diagnostics[$p] = $fit['diagnostics'];
        }
        $fit = new Fit($lambda, $coefficients, $objectives, $iterations, $eligible, $excluded, $diagnostics);
        $model = ['experiment' => Contract::VERSION, 'model_version' => Contract::MODEL_VERSION, 'selector_version' => Contract::SELECTOR_VERSION,
            'path_version' => Contract::PATH_VERSION, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'objective_reference_version' => Bt03e03Contract::OPTIMIZER_VERSION, 'stat01_anchor_coefficient' => 1.0,
            'use_restrictions' => Contract::restrictions(), 'lambda_by_position' => $lambda, 'selection_by_position' => $selections,
            'layout' => $this->layoutAudit($pool['layout']), 'position_coefficients' => $coefficients,
            'weighted_center_means' => array_map(fn ($c) => $pool['layout']->weightedMeans($c), $coefficients),
            'objectives' => $objectives, 'iterations' => $iterations, 'eligible_races' => $eligible,
            'excluded_races' => $excluded, 'optimizer_diagnostics' => $diagnostics, 'training_pool' => $pool['contract']];
        JsonlArtifact::json($directory.'/selection.json', $selections);
        JsonlArtifact::json($directory.'/model.json', $model);
        JsonlArtifact::json($directory.'/pool-reuse.json', ['pool' => $pool['name'], 'new_fit_count' => 0,
            'selected_positions_reused' => 3, 'pool_seal' => $pool['files']]);
        $rows = (function () use ($pool, $input, $fit): \Generator {
            foreach ($this->dataset->binned($input, $pool['layout'], $this->bins) as $race) {
                yield $this->predictor->predict($race, $fit);
            }
        })();
        $seal = JsonlArtifact::write($directory.'/predictions.jsonl', $rows);
        $this->verifyPool($pool);
        JsonlArtifact::json($directory.'/sealed.json', ['model' => Files::identity($directory.'/model.json'),
            'predictions' => ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']]]);
    }

    public function verifyPool(array $pool): void
    {
        Files::same($pool['files'], Files::json($pool['directory'].'/pool-sealed.json'), 'training pool seal');
        foreach ($pool['files'] as $name => $seal) {
            Files::verify($pool['directory'].'/'.$name, $seal);
        }
        Files::same($pool['contract'], Files::json($pool['directory'].'/pool-contract.json'), 'pool identity');
        Files::verify($pool['directory'].'/training.jsonl', $pool['contract']['training']);
        Files::verify($pool['directory'].'/layout.json', $pool['contract']['layout']);
        Files::same($pool['contract']['source_code'], Contract::code(), 'pooled source code');
        Files::same($this->layoutAudit($pool['layout']), Files::json($pool['directory'].'/layout.json'), 'pool layout');
        foreach ($pool['fits'] as $p => $fits) {
            foreach ($fits as $key => $fit) {
                $saved = Files::json($pool['directory'].'/'.$p.'-candidate-'.$key.'.json');
                Files::same($saved['fit'], $fit, 'pool position/lambda cached fit');
                if ($fit['diagnostics']['position'] !== $p || LossSpool::key($fit['diagnostics']['lambda']) !== (string) $key) {
                    throw new RuntimeException('Pooled fit position/lambda mismatch.');
                }
            }
        }
    }

    public function layoutAudit(Layout $layout): array
    {
        return ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(),
            'active_group_count_G' => count($layout->groups()), 'numeric_edge_count' => count($layout->smoothEdges()),
            'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(), 'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()];
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
}
