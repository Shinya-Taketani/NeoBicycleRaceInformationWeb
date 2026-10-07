<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1PositionLambda;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as BaselineLoader;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;
use Throwable;

final class Experiment
{
    public function __construct(private readonly Sources $sources, private readonly Trainer $trainer,
        private readonly Selector $selector, private readonly ModelLoader $loader,
        private readonly BaselineLoader $baselineLoader, private readonly Evaluation $evaluation) {}

    public function execute(string $input, string $baseline, string $output): array
    {
        $parent = realpath(dirname($output));
        if ($parent === false || file_exists($output) || is_link($output)) {
            throw new RuntimeException('Output must be new with an existing parent.');
        }
        foreach ([$input, $baseline] as $root) {
            $root = realpath($root);
            if ($root === false || $parent === $root || str_starts_with($parent.'/', $root.'/')) {
                throw new RuntimeException('Output overlaps or source is missing.');
            }
        }
        $source = $this->sources->open($input, $baseline);
        $code = Contract::code();
        Files::directory($output);
        JsonlArtifact::json($output.'/frozen.json', ['contract' => Contract::plan(), 'source' => $source, 'code' => $code]);
        try {
            $inspection = (new Dataset)->inspect($source, $output);
            JsonlArtifact::json($output.'/input-verification.json', $inspection);
            if (array_sum(array_column($inspection, 'entries')) < 1) {
                throw new RuntimeException('No fixed card entries: NOT_EVALUATED.');
            }
            $this->verifyBaseline($source);
            JsonlArtifact::json($output.'/baseline-forward.json', ['identical' => true, 'years' => [2024, 2025], 'retraining_count' => 0]);
            $runs = [];
            foreach (['run-01', 'run-02'] as $name) {
                $runs[$name] = $this->run($source, Files::directory($output.'/'.$name));
            }
            $first = $this->semanticFiles($output.'/run-01');
            $second = $this->semanticFiles($output.'/run-02');
            Files::same($first, $second, 'independent C1_PER_POSITION_LAMBDA training/predictions/evaluation');
            $comparison = $runs['run-01'];
            $comparison['incremental_gate'] = $this->evaluation->incrementalGate($comparison['outer']['C1_PER_POSITION_LAMBDA-C1'], $comparison['intervals']['C1_PER_POSITION_LAMBDA-C1'], true);
            $comparison['stat01_gate'] = app(Bt03e05AcceptanceGate::class)
                ->evaluate($comparison['outer']['C1_PER_POSITION_LAMBDA-STAT01'], $comparison['intervals']['C1_PER_POSITION_LAMBDA-STAT01'], true);
            Sources::verify($source);
            Files::same($code, Contract::code(), 'source code start/end');
            JsonlArtifact::json($output.'/reproduction.json', ['identical' => true, 'semantic_files' => $first, 'c1_retraining_count' => 0]);
            JsonlArtifact::json($output.'/comparisons.json', $comparison);
            $manifest = ['status' => 'COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW', 'contract' => Contract::plan(), 'source' => $source, 'code' => $code,
                'runs' => ['run-01' => $first, 'run-02' => $second],
                'pre_training_verification' => $this->semanticFiles($output.'/derived-audit'),
                'input_verification' => Files::identity($output.'/input-verification.json'),
                'baseline_forward' => Files::identity($output.'/baseline-forward.json'),
                'comparisons' => Files::identity($output.'/comparisons.json'), 'reproduction' => Files::identity($output.'/reproduction.json')];
            foreach (['run-01' => $first, 'run-02' => $second] as $run => $files) {
                foreach ($files as $name => $seal) {
                    Files::verify($output.'/'.$run.'/'.$name, $seal);
                }
            }
            foreach ($manifest['pre_training_verification'] as $name => $seal) {
                Files::verify($output.'/derived-audit/'.$name, $seal);
            }
            JsonlArtifact::json($output.'/manifest.json', $manifest);
            JsonlArtifact::json($output.'/COMPLETE.json', Files::identity($output.'/manifest.json'));

            return ['status' => $manifest['status'], 'incremental_gate' => $comparison['incremental_gate'], 'manifest' => Files::identity($output.'/manifest.json')];
        } catch (Throwable $e) {
            JsonlArtifact::json($output.'/FAILED.json', ['status' => 'NOT_EVALUATED', 'error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function run(array $source, string $directory): array
    {
        $dataset = new Dataset;
        $state = ['status' => 'RUNNING', 'steps' => []];
        $event = static function (string $step, array $details = []) use ($directory, &$state): void {
            $state['steps'][] = ['step' => $step, ...$details];
            JsonlArtifact::json($directory.'/event-'.count($state['steps']).'.json', end($state['steps']));
            echo json_encode(['step' => $step, ...$details, 'time' => gmdate('c'), 'peak_bytes' => memory_get_peak_usage(true)])."\n";
        };
        $inner = $outer = [];
        try {
            $paths = [];
            foreach ([2022, 2023] as $year) {
                $paths[$year] = $directory.'/teacher-'.$year.'.jsonl';
                JsonlArtifact::write($paths[$year], $dataset->labelled($source, $year));
            }
            $read = static function (array $years) use (&$paths): \Closure {
                return static function () use ($years, &$paths): \Generator {
                    foreach ($years as $year) {
                        yield from JsonlArtifact::read($paths[$year]);
                    }
                };
            };
            $poolSource = static function (array $years) use ($source): array {
                $seals = [];
                foreach ($years as $year) {
                    foreach (['input', 'original', 'teacher'] as $kind) {
                        $seals[$year][$kind] = $source['seals'][$source['paths'][$year][$kind]];
                    }
                }

                return $seals;
            };
            $event('T22_START');
            $poolA = $this->trainer->pool($read([2022]), [2022 => $paths[2022]], 'T22', Files::directory($directory.'/T22'), originalSource: $poolSource([2022]));
            $inner[2023] = $this->trainer->validation($poolA, $read([2023]), Files::directory($directory.'/inner-A'));
            $event('INNER_A_FINISHED');
            $selectionA = [];
            foreach (Bt03e03Contract::POSITIONS as $p) {
                $selectionA[$p] = $this->selector->select([2023 => $inner[2023][$p]], $p);
            }
            $event('T2223_START_BEFORE_OUTER2024_TEACHER');
            $poolB = $this->trainer->pool($read([2022, 2023]), array_intersect_key($paths, array_flip([2022, 2023])),
                'T2223', Files::directory($directory.'/T2223'), originalSource: $poolSource([2022, 2023]));
            foreach ([2024, 2025] as $year) {
                $selection = $selectionA;
                $pool = $poolB;
                if ($year === 2025) {
                    $event('INNER_B_REUSE_T2223_NO_FIT');
                    $inner[2024] = $this->trainer->validation($poolB, $read([2024]), Files::directory($directory.'/inner-B'));
                    foreach (Bt03e03Contract::POSITIONS as $p) {
                        $selection[$p] = $this->selector->select([2023 => $inner[2023][$p], 2024 => $inner[2024][$p]], $p);
                    }
                    $pool = $this->trainer->pool($read([2022, 2023, 2024]), array_intersect_key($paths, array_flip([2022, 2023, 2024])),
                        'T2224', Files::directory($directory.'/T2224'), array_map(fn ($s) => $s['selected_lambda'], $selection), $poolSource([2022, 2023, 2024]));
                }
                $fit = Files::directory($directory.'/C1_PER_POSITION_LAMBDA-fit-'.$year);
                $event('C1_PER_POSITION_LAMBDA_OUTER_START', ['year' => $year, 'lambda_by_position' => array_map(fn ($s) => $s['selected_lambda'], $selection)]);
                $this->trainer->predict($pool, $selection, fn () => $dataset->prediction($source, $year), $fit);
                $model = $this->loader->load($fit.'/model.json', Files::json($fit.'/sealed.json')['model']);
                $saved = JsonlArtifact::read($fit.'/predictions.jsonl');
                $saved->rewind();
                foreach ($this->loader->predictions(fn () => $dataset->prediction($source, $year), $model) as $prediction) {
                    if (! $saved->valid()) {
                        throw new RuntimeException('Reloaded model predictions incomplete.');
                    }
                    Files::same($saved->current(), $prediction, 'saved C1_PER_POSITION_LAMBDA model forward');
                    $saved->next();
                }
                if ($saved->valid()) {
                    throw new RuntimeException('Saved C1_PER_POSITION_LAMBDA model predictions extra.');
                }
                $event('C1_PER_POSITION_LAMBDA_OUTER_SEALED_AND_RELOADED', ['year' => $year]);
                $release = $dataset->release($source, $year, $fit);
                $event('OUTER_TEACHER_RELEASED', $release);
                $paths[$year] = $directory.'/teacher-'.$year.'.jsonl';
                JsonlArtifact::write($paths[$year], $dataset->labelled($source, $year));
                $outer[$year] = ['C1' => $source['paths'][$year]['baseline'], 'C1_PER_POSITION_LAMBDA' => $fit.'/predictions.jsonl', 'labels' => $paths[$year]];
            }
            JsonlArtifact::json($directory.'/teacher-access.json', $dataset->events);
            $event('BOTH_OUTER_PREDICTIONS_FIXED_BEFORE_METRICS');
            $comparison = $this->evaluation->evaluate($outer, Files::directory($directory.'/evaluation'), false);
            Sources::verify($source);
            $event('EVALUATED_PENDING_INDEPENDENT_REPRODUCTION');

            return $comparison;
        } catch (Throwable $e) {
            JsonlArtifact::json($directory.'/FAILED.json', ['status' => 'NOT_EVALUATED', 'steps' => $state['steps'], 'teacher_access' => $dataset->events,
                'error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function verifyBaseline(array $source): void
    {
        foreach ([2024, 2025] as $year) {
            $paths = $source['paths'][$year];
            $model = $this->baselineLoader->load($paths['model'], $source['seals'][$paths['model']]);
            Files::same($model->artifact['layout'], Files::json(dirname($paths['model']).'/layout.json'), 'C1 saved layout');
            $selection = Files::json(dirname($paths['model']).'/selection.json');
            Files::same([$model->fit->lambda], [$selection['lambda']], 'C1 selection lambda');
            $saved = JsonlArtifact::read($paths['baseline']);
            $saved->rewind();
            foreach ($this->baselineLoader->predictions($paths['original'], $model) as $prediction) {
                if (! $saved->valid()) {
                    throw new RuntimeException('C1 prediction missing.');
                }
                Files::same($saved->current(), $prediction, 'C1 unchanged forward');
                $saved->next();
            }
            if ($saved->valid()) {
                throw new RuntimeException('C1 prediction extra.');
            }
        }
    }

    private function semanticFiles(string $directory): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (in_array($file->getExtension(), ['json', 'jsonl'], true)) {
                $files[substr($file->getPathname(), strlen($directory) + 1)] = Files::identity($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
