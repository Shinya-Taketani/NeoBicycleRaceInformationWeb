<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Experiment as CompositionFiles;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Reader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use RuntimeException;
use Throwable;

final class Experiment
{
    public function __construct(private readonly Sources $sources, private readonly InputBuilder $inputs, private readonly Trainer $trainer,
        private readonly OneSeSelector $selector, private readonly ModelLoader $loader, private readonly Reader $reader,
        private readonly Evaluation $evaluation) {}

    public function execute(string $input, string $baseline, string $composition, string $output): array
    {
        $parent = realpath(dirname($output));
        if ($parent === false || ! str_starts_with($output, '/') || file_exists($output) || is_link($output)) {
            throw new RuntimeException('Output must be new and absolute.');
        }
        foreach ([$input, $baseline, $composition] as $path) {
            $root = realpath($path);
            if ($root === false || $parent === $root || str_starts_with($parent.'/', $root.'/') || str_starts_with($root.'/', $output.'/')) {
                throw new RuntimeException('Source/output overlap or missing source.');
            }
        }
        $source = $this->sources->open($input, $baseline, $composition);
        $code = Contract::code();
        Files::directory($output);
        JsonlArtifact::json($output.'/frozen.json', ['contract' => Contract::plan(), 'source' => $source, 'code' => $code]);
        try {
            $source = $this->inputs->build($source, Files::directory($output.'/inputs'));
            JsonlArtifact::json($output.'/input-verification.json', (new Dataset)->inspect($source));
            $runs = [];
            foreach (['run-01', 'run-02'] as $run) {
                $runs[$run] = $this->run($source, Files::directory($output.'/'.$run));
            }
            $first = CompositionFiles::semanticFiles($output.'/run-01');
            $second = CompositionFiles::semanticFiles($output.'/run-02');
            Files::same($first, $second, 'independent C3 training/prediction/evaluation');
            $result = $runs['run-01'];
            $result['gates'] = $this->evaluation->gates($result, true);
            $old = Files::json($composition.'/comparison.json');
            foreach ([2024, 2025] as $year) {
                Files::same($old['outer']['CANDIDATE-C1'][$year], $result['outer']['B-A'][$year], 'saved PR92 evaluation');
                Files::same($old['outer']['PRIMARY-CANDIDATE-STAT01'][$year]['candidate'],
                    $result['outer']['PRIMARY-B-A'][$year]['candidate'], 'PR92 Primary exact-order evaluation');
            }
            Files::same($old['intervals']['CANDIDATE-C1'], $result['intervals']['B-A'], 'PR92 paired bootstrap');
            $orderDelta = array_sum(array_map(fn ($v) => $v['delta']['EXACT_ORDERED_TOP3_RATE'], $result['outer']['PRIMARY-C-B'])) / 2;
            $passed = $result['gates']['C-B']['status'] === 'PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY';
            $result['statuses'] = ['implementation_status' => 'IMPLEMENTED_TESTED', 'evaluation_status' => 'MEASURED_REPRODUCED',
                'incremental_effect_vs_C2' => $passed ? 'DEVELOPMENT_GATE_PASSED' : 'NOT_CONFIRMED',
                'total_effect_vs_C1' => $result['gates']['C-A']['status'], 'exact_order_effect' => $orderDelta < 0 ? 'DETERIORATED_VS_C2' : 'NOT_DETERIORATED_VS_C2',
                'development_gate' => $result['gates']['C-B']['status'], 'accuracy_progress' => $passed && $orderDelta >= 0 ? 'LIMITED_DEVELOPMENT_EVIDENCE' : 'NOT_CONFIRMED',
                'historical_as_of_available' => false, 'formal_adoption' => false, 'adoption' => 'NOT_ADOPTED'];
            JsonlArtifact::json($output.'/comparison.json', $result);
            JsonlArtifact::json($output.'/reproduction.json', ['identical' => true, 'files' => $first,
                'independent_runs' => 2, 'baseline_retraining_count' => 0, 'samples_counted_once' => true]);
            Sources::verify($source);
            Files::same($code, Contract::code(), 'code START/END');
            $files = CompositionFiles::semanticFiles($output);
            foreach ($files as $path => $seal) {
                Files::verify($output.'/'.$path, $seal);
            }
            Sources::verify($source);
            Files::same($code, Contract::code(), 'prepublication code');
            JsonlArtifact::json($output.'/manifest.json', ['contract' => Contract::plan(), 'statuses' => $result['statuses'],
                'source' => $source, 'code' => $code, 'files' => $files]);
            JsonlArtifact::json($output.'/COMPLETE.json', Files::identity($output.'/manifest.json'));

            return ['statuses' => $result['statuses'], 'manifest' => Files::identity($output.'/manifest.json')];
        } catch (Throwable $e) {
            JsonlArtifact::json($output.'/FAILED.json', ['evaluation_status' => 'NOT_EVALUATED', 'accuracy_progress' => null,
                'error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function run(array $source, string $directory): array
    {
        $dataset = new Dataset;
        $paths = $inner = $outer = $events = [];
        $event = static function (string $name, array $details = []) use (&$events, $directory): void {
            $events[] = ['event' => $name, ...$details];
            JsonlArtifact::json($directory.'/event-'.count($events).'.json', end($events));
            echo json_encode(['phase' => $name, ...$details, 'time' => gmdate('c'), 'peak_bytes' => memory_get_peak_usage(true)])."\n";
        };
        $read = static function (array $years) use (&$paths): \Closure {
            return static function () use ($years, &$paths): \Generator {
                foreach ($years as $year) {
                    yield from JsonlArtifact::read($paths[$year]);
                }
            };
        };
        try {
            foreach ([2022, 2023] as $year) {
                $paths[$year] = $directory.'/teacher-'.$year.'.jsonl';
                JsonlArtifact::write($paths[$year], $dataset->labelled($source, $year));
            }
            foreach ([2024, 2025] as $year) {
                $fold = $year === 2024 ? 'A' : 'B';
                $event('C3_INNER_'.$fold.'_START');
                $inner[$year - 1] = $this->trainer->grid($read($year === 2024 ? [2022] : [2022, 2023]), $read([$year - 1]),
                    Files::directory($directory.'/C3-inner-'.$fold));
                $selection = $this->selector->select(array_map(fn ($v) => $v['losses'], $inner));
                $fit = Files::directory($directory.'/C3-fit-'.$year);
                JsonlArtifact::json($fit.'/selection.json', $selection);
                $event('C3_OUTER_START', ['year' => $year, 'lambda' => $selection['lambda']]);
                $this->trainer->refit($read($year === 2024 ? [2022, 2023] : [2022, 2023, 2024]), fn () => $dataset->prediction($source, $year), $selection['lambda'], $fit);
                $model = $this->loader->load($fit.'/model.json', Files::json($fit.'/sealed.json')['model']);
                $saved = JsonlArtifact::read($fit.'/predictions.jsonl');
                $saved->rewind();
                foreach ($this->loader->predictions(fn () => $dataset->prediction($source, $year), $model) as $prediction) {
                    if (! $saved->valid()) {
                        throw new RuntimeException('Reloaded C3 model predictions missing.');
                    }
                    Files::same($saved->current(), $prediction, 'saved C3 forward');
                    $saved->next();
                }
                if ($saved->valid()) {
                    throw new RuntimeException('Reloaded C3 model predictions extra.');
                }
                $composed = $directory.'/predictions-'.$year.'.jsonl';
                JsonlArtifact::write($composed, $this->compose($source, $year, $fit));
                Files::verify($composed, Files::json($composed.'.manifest.json'));
                $event('C3_MODEL_COMPOSITION_AND_COHORT_SEALED', ['year' => $year, 'model' => Files::identity($fit.'/model.json'), 'composition' => Files::identity($composed)]);
                $release = $dataset->release($source, $year, $fit);
                $event('OUTER_TEACHER_RELEASED', $release);
                $paths[$year] = $directory.'/teacher-'.$year.'.jsonl';
                JsonlArtifact::write($paths[$year], $dataset->labelled($source, $year));
                $outer[$year] = ['A' => $source['paths'][$year]['baseline'], 'B' => $source['paths'][$year]['composition'], 'C' => $composed, 'labels' => $paths[$year]];
            }
            JsonlArtifact::json($directory.'/teacher-access.json', $dataset->events);
            $event('BOTH_OUTER_PREDICTIONS_FIXED_BEFORE_METRICS');
            $result = $this->evaluation->evaluate($outer, Files::directory($directory.'/evaluation'));
            Sources::verify($source);

            return $result;
        } catch (Throwable $e) {
            JsonlArtifact::json($directory.'/FAILED.json', ['evaluation_status' => 'NOT_EVALUATED', 'events' => $events, 'teacher_access' => $dataset->events,
                'error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function compose(array $source, int $year, string $fit): \Generator
    {
        Reader::year($year);
        $streams = [];
        foreach (['A' => $source['paths'][$year]['baseline'], 'B' => $source['paths'][$year]['composition'], 'C3' => $fit.'/predictions.jsonl'] as $key => $path) {
            $streams[$key] = JsonlArtifact::read($path);
            $streams[$key]->rewind();
        }
        foreach (Artifacts::lines($source['paths'][$year]['input']) as $input) {
            foreach ($streams as $stream) {
                if (! $stream->valid()) {
                    throw new RuntimeException('Composition parent omitted race.');
                }
            }
            $b = $streams['B']->current();
            Files::same([$input['year'], $input['race_id'], array_map(fn ($e) => [$e['id'], $e['bike']], $input['entries'])],
                [$b['year'], $b['race_id'], $b['cohort']], 'saved B composition cohort');
            $row = $this->reader->row($input, $streams['A']->current(), $streams['C3']->current());
            $row['candidate']['calculation_version'] = Contract::VERSION;
            $row['provenance'] = ['contract' => Contract::VERSION, 'position_sources' => Contract::plan()['position_sources'],
                'C1_model' => $source['seals'][$source['paths'][$year]['model']], 'C3_model' => Files::identity($fit.'/model.json')];
            yield $row;
            foreach ($streams as $stream) {
                $stream->next();
            }
        }
        foreach ($streams as $stream) {
            if ($stream->valid()) {
                throw new RuntimeException('Composition parent extra race.');
            }
        }
        Files::verify($source['paths'][$year]['input'], $source['seals'][$source['paths'][$year]['input']]);
        Files::verify($source['paths'][$year]['composition'], $source['seals'][$source['paths'][$year]['composition']]);
        Files::verify($fit.'/predictions.jsonl', Files::json($fit.'/sealed.json')['predictions']);
    }
}
