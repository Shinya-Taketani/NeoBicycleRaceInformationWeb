<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat36C1Comparison;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as BaselineLoader;
use RuntimeException;
use Throwable;

final class Experiment
{
    public function __construct(private readonly Sources $sources, private readonly Trainer $trainer,
        private readonly Bt03e03OneSeSelector $selector, private readonly ModelLoader $loader,
        private readonly BaselineLoader $baselineLoader, private readonly Evaluation $evaluation) {}

    public function execute(string $input, string $baseline, string $candidate, string $output): array
    {
        $parent = realpath(dirname($output));
        if ($parent === false || file_exists($output) || is_link($output)) {
            throw new RuntimeException('Output must be new with an existing parent.');
        }
        foreach ([$input, $baseline, $candidate] as $root) {
            $root = realpath($root);
            if ($root === false || $parent === $root || str_starts_with($parent.'/', $root.'/')) {
                throw new RuntimeException('Output overlaps or source is missing.');
            }
        }
        $source = $this->sources->open($input, $baseline, $candidate);
        $code = Contract::code();
        Files::directory($output);
        JsonlArtifact::json($output.'/frozen.json', ['contract' => Contract::plan(), 'source' => $source, 'code' => $code]);
        try {
            JsonlArtifact::json($output.'/input-verification.json', (new Dataset)->inspect($source));
            $this->verifyBaseline($source);
            JsonlArtifact::json($output.'/baseline-forward.json', ['identical' => true, 'years' => [2024, 2025], 'retraining_count' => 0]);
            $runs = [];
            foreach (['run-01', 'run-02'] as $name) {
                $runs[$name] = $this->run($source, Files::directory($output.'/'.$name));
            }
            $first = $this->semanticFiles($output.'/run-01');
            $second = $this->semanticFiles($output.'/run-02');
            Files::same($first, $second, 'independent C1_PLUS_S training/predictions/evaluation');
            $comparison = $runs['run-01'];
            $comparison['incremental_gate'] = $this->evaluation->incrementalGate($comparison['outer']['C1_PLUS_S-C1'], $comparison['intervals']['C1_PLUS_S-C1'], true);
            $comparison['stat01_gate'] = app(Bt03e05AcceptanceGate::class)
                ->evaluate($comparison['outer']['C1_PLUS_S-STAT01'], $comparison['intervals']['C1_PLUS_S-STAT01'], true);
            Sources::verify($source);
            Files::same($code, Contract::code(), 'source code start/end');
            JsonlArtifact::json($output.'/reproduction.json', ['identical' => true, 'semantic_files' => $first, 'c1_retraining_count' => 0]);
            JsonlArtifact::json($output.'/comparisons.json', $comparison);
            $manifest = ['status' => 'COMPLETED_NOT_ADOPTED', 'contract' => Contract::plan(), 'source' => $source, 'code' => $code,
                'runs' => ['run-01' => $first, 'run-02' => $second],
                'comparisons' => Files::identity($output.'/comparisons.json'), 'reproduction' => Files::identity($output.'/reproduction.json')];
            foreach (['run-01' => $first, 'run-02' => $second] as $run => $files) {
                foreach ($files as $name => $seal) {
                    Files::verify($output.'/'.$run.'/'.$name, $seal);
                }
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
            foreach ([2024, 2025] as $year) {
                $fold = $year === 2024 ? 'A' : 'B';
                $event('C1_PLUS_S_INNER_'.$fold.'_START');
                $inner[$year - 1] = $this->trainer->grid($read($year === 2024 ? [2022] : [2022, 2023]), $read([$year - 1]),
                    Files::directory($directory.'/C1_PLUS_S-inner-'.$fold));
                $event('C1_PLUS_S_INNER_'.$fold.'_FINISHED');
                $selection = $this->selector->select(array_map(fn ($v) => $v['losses'], $inner));
                $fit = Files::directory($directory.'/C1_PLUS_S-fit-'.$year);
                JsonlArtifact::json($fit.'/selection.json', $selection);
                $event('C1_PLUS_S_OUTER_START', ['year' => $year, 'lambda' => $selection['lambda']]);
                $this->trainer->refit($read($year === 2024 ? [2022, 2023] : [2022, 2023, 2024]),
                    fn () => $dataset->prediction($source, $year), $selection['lambda'], $fit);
                $model = $this->loader->load($fit.'/model.json', Files::json($fit.'/sealed.json')['model']);
                $saved = JsonlArtifact::read($fit.'/predictions.jsonl');
                $saved->rewind();
                foreach ($this->loader->predictions(fn () => $dataset->prediction($source, $year), $model) as $prediction) {
                    if (! $saved->valid()) {
                        throw new RuntimeException('Reloaded model predictions incomplete.');
                    }
                    Files::same($saved->current(), $prediction, 'saved C1_PLUS_S model forward');
                    $saved->next();
                }
                if ($saved->valid()) {
                    throw new RuntimeException('Saved C1_PLUS_S model predictions extra.');
                }
                $event('C1_PLUS_S_OUTER_SEALED_AND_RELOADED', ['year' => $year]);
                $release = $dataset->release($source, $year, $fit);
                $event('OUTER_TEACHER_RELEASED', $release);
                $paths[$year] = $directory.'/teacher-'.$year.'.jsonl';
                JsonlArtifact::write($paths[$year], $dataset->labelled($source, $year));
                $outer[$year] = ['C1' => $source['paths'][$year]['baseline'], 'C1_PLUS_S' => $fit.'/predictions.jsonl', 'labels' => $paths[$year]];
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
