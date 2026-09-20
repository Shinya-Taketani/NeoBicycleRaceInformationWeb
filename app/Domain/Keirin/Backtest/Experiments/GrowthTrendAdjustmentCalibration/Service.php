<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;
use Throwable;

final class Service
{
    public function __construct(private readonly Sources $sources, private readonly Inputs $builder, private readonly Engine $engine,
        private readonly ModelLoader $models, private readonly Store $store, private readonly ResultStore $writer,
        private readonly Code $code, private readonly Comparison $comparison) {}

    public function execute(string $root, string $id, string $outer, string $trend): array
    {
        Contract::path($root);
        $this->id($id);

        return $this->run($root, $id, $this->sources->open($outer, $trend), null);
    }

    public function reproduce(string $root, string $id): array
    {
        Contract::path($root);
        $this->id($id);
        $bundle = $root.'/evaluations/'.$id;
        $original = $this->store->verify($bundle);
        Files::same(Files::json($bundle.'/code.json'), $this->code->capture(), 'reproduction code');

        return $this->run($root, $id, Files::json($bundle.'/sources.json'), $original);
    }

    private function run(string $root, string $id, array $sources, ?array $original): array
    {
        $this->sources->verify($sources);
        $this->writer->prepare($root, $sources['files']);

        return $this->writer->locked($root, $id, function () use ($root, $id, $sources, $original): array {
            $destination = $root.'/evaluations/'.$id;
            if ($original === null && (file_exists($destination) || is_link($destination))) {
                throw new RuntimeException('Fixed artifacts cannot be overwritten; use reproduce.');
            }
            $stage = Files::directory($root.'/.staging/'.$id.($original ? '-reproduce-' : '-').bin2hex(random_bytes(8)));
            $expected = [];
            try {
                $access = new TemporalAccess;
                $code = $this->code->capture();
                foreach (['contract' => Contract::plan(), 'candidate-grid' => Contract::grid(), 'code' => $code, 'sources' => $sources] as $name => $value) {
                    $expected += $this->writer->writeJson($stage, $name.'.json', $value);
                }
                $access->record('SAFE_SOURCE_VERIFIED');
                $expected += $this->writer->writeJsonl($stage, 'signal-projection.jsonl', Signal::projection(JsonlArtifact::read($sources['trend'])));
                $access->record('SIGNAL_PROJECTED');
                $workspace = new Workspace($stage.'/workspace.sqlite');
                $counts = $workspace->load($stage.'/signal-projection.jsonl', $sources['meeting']);
                Files::same($sources['entries'], $counts['entries'], 'signal cohort counts');
                $models = $inputs = $audit = [];
                foreach (Contract::YEARS as $year) {
                    $paths = $sources['years'][$year];
                    $models[$year] = $this->models->load($paths['model'], $sources['files'][$paths['model']]);
                    $audit[$year] = [];
                    $name = 'prediction-input-'.$year.'.jsonl';
                    $expected += $this->writer->writeJsonl($stage, $name, $this->builder->build($year, $paths, $models[$year], $workspace, $audit[$year]));
                    if ($audit[$year]['races'] !== $sources['counts'][$year] || $audit[$year]['entries'] !== $sources['entries'][$year]) {
                        throw new RuntimeException('Fixed cohort count mismatch.');
                    }
                    $inputs[$year] = $stage.'/'.$name;
                    $access->record('OUTCOME_FREE_PREDICTIONS_VERIFIED', $year);
                    echo json_encode(['phase' => 'OUTCOME_FREE_PREDICTIONS_VERIFIED', 'year' => $year, 'counts' => $audit[$year]])."\n";
                }
                $workspace->complete();
                $scaling = $workspace->scaling() + ['source_identity' => $expected['signal-projection.jsonl'],
                    'fixed_trend_sources' => array_filter($sources['files'], fn ($path) => str_starts_with($path, dirname($sources['trend']).'/'), ARRAY_FILTER_USE_KEY)];
                $cuts = $workspace->marginCuts();
                unset($workspace);
                unlink($stage.'/workspace.sqlite');
                $scale = $scaling['abs_raw_p99'];
                $expected += $this->writer->writeJson($stage, 'prediction-reproduction.json', $audit);
                $expected += $this->writer->writeJson($stage, 'preflight.json', ['status' => 'PASS', 'counts' => $counts, 'margin_quartiles' => $cuts, 'database' => 'NONE']);
                $expected += $this->writer->writeJson($stage, 'signal-scaling.json', $scaling);
                $expected += $this->writer->writeJson($stage, 'signal-scaling-seal.json', $expected['signal-scaling.json']);
                $access->seal('signal-scaling', $stage, $expected);
                $outcomes[2024] = $this->sources->outcome($sources['outer_root'], 2024, $access);
                $paths = $sources['years'];
                $paths[2024] += $outcomes[2024]['paths'];
                $baseline24 = $this->engine->baseline(2024, $inputs[2024], $paths[2024], $access);
                $expected += $this->writer->writeJson($stage, 'baseline-2024.json', $baseline24);
                $curve24 = $this->engine->curve(2024, $inputs[2024], $paths[2024], $models[2024], $scale, $baseline24['metrics'], $access);
                $expected += $this->store->curve($stage, 'coefficient-curve-2024', $curve24);
                $source24 = $outcomes[2024]['files'];
                foreach ($sources['years'][2024] as $path) {
                    if (isset($sources['files'][$path])) {
                        $source24[$path] = $sources['files'][$path];
                    }
                }
                $selection = Contract::select($curve24) + ['candidate_grid' => $expected['candidate-grid.json'],
                    'scaling_seal' => $expected['signal-scaling-seal.json'], 'sources_2024' => $source24,
                    'code' => $expected['code.json'], 'prediction_input_2024' => $expected['prediction-input-2024.jsonl'],
                    'curve_2024' => $expected['coefficient-curve-2024.json']];
                $expected += $this->writer->writeJson($stage, 'selection.json', $selection);
                $expected += $this->writer->writeJson($stage, 'selection-seal.json', $expected['selection.json']);
                $access->seal('selection', $stage, $expected);
                echo json_encode(['phase' => 'SELECTION_SEALED', 'k' => $selection['selected']['k'], 'scale' => $scale])."\n";
                $outcomes[2025] = $this->sources->outcome($sources['outer_root'], 2025, $access);
                $paths[2025] += $outcomes[2025]['paths'];
                $expected += $this->writer->writeJson($stage, 'outcome-sources.json', $outcomes);
                $fixedSeals = array_map(fn (array $o) => $o['fixed_seals'], $outcomes);
                $expected += $this->writer->writeJson($stage, 'fixed-outcome-seals.json', $fixedSeals);
                $expected += $this->writer->writeJson($stage, 'outcome-source-trust-audit.json', [
                    'trust_anchor_type' => Sources::OUTCOME_TRUST_ANCHOR, 'report_export_manifest_used' => false,
                    'years' => array_map(fn (array $o) => ['labels_sidecar_fixed' => true, 'labels_body_fixed' => true,
                        'contributions_sidecar_fixed' => true, 'contributions_body_fixed' => true,
                        'sidecar_content_matches_fixed_body_seal' => true, 'fixed_seals' => $o['fixed_seals']], $outcomes),
                    '2024_verified_after_scaling_seal' => true, '2025_verified_after_selection_seal' => true,
                    'body_and_sidecar_consistent_mutation_policy' => 'REJECT',
                ]);
                $baseline25 = $this->engine->baseline(2025, $inputs[2025], $paths[2025], $access);
                $expected += $this->writer->writeJson($stage, 'baseline-2025.json', $baseline25);
                $k = $selection['selected']['k'];
                $fixed = $this->engine->curve(2025, $inputs[2025], $paths[2025], $models[2025], $scale, $baseline25['metrics'], $access, $k)['candidates'][0];
                $transfer = ['purpose' => 'POST_SELECTION_DEVELOPMENT_TRANSFER', 'selected' => $fixed,
                    'baseline' => $baseline25, 'status' => Contract::transfer($fixed), 'selection_seal' => $expected['selection-seal.json']];
                $expected += $this->writer->writeJson($stage, 'transfer-2025.json', $transfer);
                $curve25 = $this->engine->curve(2025, $inputs[2025], $paths[2025], $models[2025], $scale, $baseline25['metrics'], $access);
                Files::same($fixed, $curve25['candidates'][$k + 50], 'fixed transfer versus diagnostic candidate');
                $expected += $this->store->curve($stage, 'coefficient-curve-2025', $curve25);
                $expected += $this->store->curve($stage, 'coefficient-curve-pooled', Engine::pooled($curve24, $curve25));
                $diagnostics = [];
                $expected += $this->writer->writeJsonl($stage, 'selected-weight-details.jsonl', $this->engine->selected($inputs, $paths, $models, $k, $scale, $cuts, $access, $diagnostics));
                foreach ([2024 => $curve24, 2025 => $curve25] as $year => $curve) {
                    Files::same($curve['candidates'][$k + 50]['metrics'], $diagnostics['changed-race'][$year]['ALL']['metrics'], 'selected detail metric totals');
                    Files::same($curve['candidates'][$k + 50]['changes'], $diagnostics['changed-race'][$year]['ALL']['changes'], 'selected detail decision changes');
                }
                foreach ($diagnostics as $kind => $value) {
                    $expected += $this->writer->writeJson($stage, $kind.'-diagnostics.json', $value);
                }
                $comparison = $this->comparison->read($access, $selection['selected'], $transfer);
                $expected += $this->writer->writeJson($stage, 'old-calibration-comparison.json', $comparison);
                $this->sources->verify($sources);
                $allFiles = $sources['files'] + $comparison['files'];
                foreach ($outcomes as $o) {
                    OuterSource::verify($o['files']);
                    $allFiles += $o['files'];
                }
                OuterSource::verify($comparison['files']);
                Files::same($code, $this->code->capture(), 'end code integrity');
                $expected += $this->writer->writeJson($stage, 'temporal-access-audit.json', $access->artifact());
                $expected += $this->writer->writeJson($stage, 'source-end.json', ['status' => 'UNCHANGED', 'files' => $allFiles,
                    'outcome_trust_anchor_type' => Sources::OUTCOME_TRUST_ANCHOR, 'fixed_outcome_seals' => $fixedSeals,
                    'database' => 'NONE', '2026_access' => 0]);
                $this->writer->verifyGenerated($stage, $expected);
                if ($original !== null) {
                    Files::same($original['files'], $expected, 'all reproduced files');
                    Files::same($original, $this->store->verify($destination), 'fixed bundle unchanged');
                    // Deterministic manifest and lock are reproduced too, never republished.
                    $m = $this->writer->writeJson($stage, 'manifest.json', $original);
                    $this->writer->writeJson($stage, 'LOCKED.json', $m['manifest.json']);
                    $this->store->verify($stage);
                    Files::same(Files::identity($destination.'/LOCKED.json'), Files::identity($stage.'/LOCKED.json'), 'lock byte reproduction');
                } else {
                    $this->store->publish($stage, $destination, $expected);
                }
                $receipt = ['status' => $original ? 'REPRODUCED' : 'CALIBRATION_LOCKED', 'path' => $original ? $stage : $destination,
                    'scale_p99' => $scale, 'selected_k' => $k, 'transfer_status' => $transfer['status'], 'database' => 'NONE', '2026_access' => 0,
                    'verified_files' => count($expected) + 2, 'peak_bytes' => memory_get_peak_usage(true)];
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
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $id) !== 1 || preg_match('/(?:^|-)2026(?:-|$)/', $id)) {
            throw new RuntimeException('Invalid analysis ID/year.');
        }
    }
}
