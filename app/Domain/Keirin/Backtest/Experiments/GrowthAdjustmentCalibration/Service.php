<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;
use Throwable;

final class Service
{
    public function __construct(private readonly Sources $sources, private readonly Projection $projection, private readonly Preflight $preflight,
        private readonly Engine $engine, private readonly ModelLoader $models, private readonly Store $store, private readonly ResultStore $writer, private readonly Code $code) {}

    public function execute(string $root, string $id, string $outerRoot, string $growthBundle): array
    {
        return $this->run($root, $id, $this->sources->open($outerRoot, $growthBundle), null);
    }

    public function reproduce(string $root, string $id): array
    {
        $this->id($id);
        $path = $root.'/evaluations/'.$id;
        $manifest = $this->store->verify($path);
        Files::same(Files::json($path.'/code.json'), $this->code->capture(), 'reproduction code');

        return $this->run($root, $id, Files::json($path.'/sources.json'), $manifest);
    }

    private function run(string $root, string $id, array $sources, ?array $original): array
    {
        $this->id($id);
        $this->sources->verify($sources);
        $this->writer->prepare($root, $sources['files']);

        return $this->writer->locked($root, $id, function () use ($root, $id, $sources, $original): array {
            $destination = $root.'/evaluations/'.$id;
            if ($original === null && (file_exists($destination) || is_link($destination))) {
                throw new RuntimeException('Use reproduce; fixed artifacts cannot be overwritten.');
            }
            $stage = Files::directory($root.'/.staging/'.$id.($original ? '-reproduce-' : '-').bin2hex(random_bytes(8)));
            $expected = [];
            try {
                $code = $this->code->capture();
                $expected += $this->writer->writeJson($stage, 'contract.json', Contract::plan());
                $expected += $this->writer->writeJson($stage, 'candidate-grid.json', Contract::grid());
                $expected += $this->writer->writeJson($stage, 'sources.json', $sources);
                $expected += $this->writer->writeJson($stage, 'code.json', $code);
                $expected += $this->writer->writeJsonl($stage, 'growth-signal-input.jsonl', $this->projection->growth(JsonlArtifact::read($sources['growth'])));
                $workspace = new Workspace($stage.'/workspace.sqlite');
                $counts = $workspace->load($stage.'/growth-signal-input.jsonl', $sources['cohort']);
                foreach (['entries', 'missing', 'same'] as $kind) {
                    Files::same($sources[$kind], $counts[$kind], 'fixed growth '.$kind);
                }
                $models = $inputs = $baseline = [];
                foreach (Contract::YEARS as $year) {
                    $paths = $sources['years'][$year];
                    $models[$year] = $this->models->load($paths['model'], $sources['files'][$paths['model']]);
                    $audit = [];
                    $name = 'prediction-input-'.$year.'.jsonl';
                    $expected += $this->writer->writeJsonl($stage, $name, $this->preflight->inputs($paths, $year, $models[$year], $workspace, $audit));
                    if ($audit['races'] !== $sources['counts'][$year] || $audit['entries'] !== $sources['entries'][$year]) {
                        throw new RuntimeException('Preflight cohort size mismatch.');
                    }
                    $inputs[$year] = $stage.'/'.$name;
                    $baseline[$year] = $audit;
                    echo json_encode(['phase' => 'BASELINE_VERIFIED', 'year' => $year, 'races' => $audit['races']])."\n";
                }
                $workspace->complete();
                unset($workspace);
                unlink($stage.'/workspace.sqlite');
                $expected += $this->writer->writeJson($stage, 'preflight.json', ['status' => 'PASS', 'counts' => $counts, 'database' => 'NONE']);
                $expected += $this->writer->writeJson($stage, 'baseline-reproduction.json', $baseline);
                $curve24 = $this->engine->curve(2024, $inputs[2024], $sources['years'][2024], $models[2024], $baseline[2024]['metrics']);
                $expected += $this->store->curve($stage, 'coefficient-curve-2024', $curve24);
                $selection = Contract::select($curve24) + ['candidate_grid' => $expected['candidate-grid.json'], 'sources' => $sources['files'],
                    'baseline_2024' => $baseline[2024]['metrics'], 'curve_2024' => $expected['coefficient-curve-2024.json']];
                $expected += $this->writer->writeJson($stage, 'selection.json', $selection);
                $selectionSeal = $expected['selection.json'];
                $expected += $this->writer->writeJson($stage, 'selection-seal.json', $selectionSeal);
                echo json_encode(['phase' => 'SELECTION_SEALED', 'k' => $selection['selected']['k'], 'seal' => $selectionSeal])."\n";
                $curve25 = $this->engine->curve(2025, $inputs[2025], $sources['years'][2025], $models[2025], $baseline[2025]['metrics'], $stage.'/selection.json', $selectionSeal);
                $expected += $this->store->curve($stage, 'coefficient-curve-2025', $curve25);
                $selected25 = $curve25['candidates'][$selection['selected']['k'] + 50];
                $validation = ['selected' => $selected25, 'status' => Contract::validation($selected25), 'selection_seal' => $selectionSeal];
                $expected += $this->writer->writeJson($stage, 'validation-2025.json', $validation);
                $expected += $this->store->curve($stage, 'coefficient-curve-pooled', Engine::pooled($curve24, $curve25));
                $diagnostics = [];
                $expected += $this->writer->writeJsonl($stage, 'selected-weight-details.jsonl', $this->engine->selected($inputs, $sources['years'], $models, $selected25['k'], $diagnostics));
                foreach (Contract::YEARS as $year) {
                    $d = $diagnostics['years'][$year];
                    Files::same($baseline[$year]['metrics'], $d['baseline'], 'selected baseline totals');
                    $curve = $year === 2024 ? $curve24 : $curve25;
                    Files::same($curve['candidates'][$selected25['k'] + 50]['metrics'], Metrics::finish($d['adjusted'], $d['baseline']), 'selected replay metrics');
                    if ($d['same_zero'] !== $sources['same'][$year] || $d['missing'] !== $sources['missing'][$year]) {
                        throw new RuntimeException('Selected growth coverage mismatch.');
                    }
                }
                $strata = $diagnostics['strata'];
                foreach ($strata as &$stratum) {
                    $stratum['metrics'] = Metrics::finish($stratum['adjusted'], $stratum['baseline']);
                    $stratum['purpose'] = 'SELECTED_GLOBAL_WEIGHT_DIAGNOSTIC_ONLY';
                }
                unset($stratum);
                unset($diagnostics['strata']);
                $expected += $this->writer->writeJson($stage, 'diagnostics.json', $diagnostics);
                $expected += $this->writer->writeJson($stage, 'grade-class-diagnostics.json', array_values($strata));
                $this->sources->verify($sources);
                Files::same($code, $this->code->capture(), 'end code integrity');
                Files::verify($stage.'/selection.json', $selectionSeal);
                $expected += $this->writer->writeJson($stage, 'source-end.json', ['status' => 'UNCHANGED', 'files' => $sources['files'], 'database' => 'NONE', '2026_access' => 0]);
                $this->writer->verifyGenerated($stage, $expected);
                if ($original !== null) {
                    Files::same($original['files'], $expected, 'all reproduced calibration files');
                    Files::same($original, $this->store->verify($destination), 'reproduce end bundle');
                } else {
                    $this->store->publish($stage, $destination, $expected);
                }
                $receipt = ['status' => $original ? 'REPRODUCED' : 'CALIBRATION_LOCKED', 'path' => $original ? $stage : $destination,
                    'selected' => $selection['selected'], 'validation' => $validation, 'database' => 'NONE', '2026_access' => 0, 'peak_bytes' => memory_get_peak_usage(true)];
                $this->writer->event($root, $id, $receipt);

                return $receipt;
            } catch (Throwable $e) {
                JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_NOT_PUBLISHED', 'error' => $e->getMessage(), 'expected' => $expected]);
                throw $e;
            }
        });
    }

    private function id(string $id): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $id) !== 1) {
            throw new RuntimeException('Invalid analysis ID.');
        }
    }
}
