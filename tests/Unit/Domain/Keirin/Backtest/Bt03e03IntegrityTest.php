<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e03ArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e03ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e03PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Tests\TestCase;

class Bt03e03IntegrityTest extends TestCase
{
    public function test_temporal_fold_and_2026_guard_contracts_are_fixed(): void
    {
        $plan = Bt03e03Contract::plan();

        $this->assertSame('inner 2022->2023; refit 2022-2023; outer 2024', $plan['outer_folds']['2024']);
        $this->assertSame('inner 2022->2023 + 2022-2023->2024; refit 2022-2024; outer 2025', $plan['outer_folds']['2025']);
        $this->assertSame('FORBIDDEN', $plan['2026_access']);
        $this->assertSame([2022, 2023, 2024, 2025], Bt03e03Contract::DEVELOPMENT_YEARS);

        $audit = new Bt03e02ReadOnlyQueryAudit;
        $audit->start();
        try {
            $audit->recordSnapshotYear(2026);
            $this->fail('The shared semantic guard must reject 2026 before a read.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forbidden', $exception->getMessage());
        } finally {
            $audit->finish();
        }
    }

    public function test_frozen_numeric_contract_is_fully_exposed_by_the_plan(): void
    {
        $plan = Bt03e03Contract::plan();

        $this->assertSame(Bt03e03Contract::FIT_EXECUTION_ORDER, $plan['fit_execution_order']);
        $this->assertSame([
            'max_iterations' => 200,
            'max_iterations_semantics' => 'ACCEPTED_PARAMETER_UPDATES',
            'convergence_tolerance' => 1e-7,
            'objective_tolerance' => 1e-10,
            'initial_step' => 1.0,
            'backtrack_factor' => 0.5,
            'max_line_search_steps' => 24,
            'restart_rule' => 'MONOTONE_OBJECTIVE_RESTART_SAME_UPDATE_RETRY-v2',
        ], $plan['solver_constants']);
        $this->assertSame([
            'iterations' => 2000,
            'seed' => 20260812,
            'ci_lower_quantile' => 0.025,
            'ci_upper_quantile' => 0.975,
            'resampling_unit' => 'YEAR_STRATIFIED_RACE_CLUSTER',
        ], $plan['bootstrap']);
        $this->assertSame(1e-12, $plan['probability_tolerance']);
        $this->assertSame(Bt03e03Contract::PREDICTION_MANIFEST_VERSION, $plan['prediction_manifest_version']);
        $this->assertSame([
            'non_inferiority' => ['primary_ci_lower_gt' => -0.0015],
            'superiority' => [
                'hit3_ci_lower_gt' => 0.0,
                'one_of_win_p2_p3_ci_lower_gt' => 0.0,
                'one_of_win_p2_p3_positive_min_count' => 1,
                'primary_year_equal_positive_min_count' => 3,
            ],
            'temporal_stability' => ['each_outer_primary_delta_gte' => -0.003],
            'supporting' => [
                'non_negative_min_count' => 4,
                'non_negative_threshold' => 0.0,
                'none_below' => -0.002,
            ],
            'tie_quality' => [
                'technical_tiebreak_rate_lte' => 0.001,
                'candidate_tie_rate_lte_baseline' => true,
            ],
            'position_redesign' => [
                'winner_year_equal_gt' => 0.0,
                'p2_year_equal_gte' => 0.0,
                'p3_year_equal_gt' => 0.0,
                'hit3_year_equal_gt' => 0.0,
            ],
            'win_preservation' => ['each_outer_year_winner_delta_gte' => 0.0],
        ], $plan['acceptance_gate']);
        $this->assertSame('BT03E03-POSITION-PROBABILITY-v2', $plan['calculation_version']);
        $this->assertSame('BT03E03-FISTA-POSITION-SOFTMAX-v2', $plan['optimizer_version']);
        $this->assertSame('BT03E03-DEVELOPMENT-ARTIFACT-v2', $plan['artifact_version']);
        $this->assertSame('BT03E03-ACCEPTED-UPDATE-BUDGET-v1', $plan['iteration_semantics_version']);
        $this->assertSame('BT03E03-SEQUENTIAL-MARGINAL-v1', $plan['probability_version']);
        $this->assertSame('BT03E03-PREDICTION-SEMANTIC-MANIFEST-v1', $plan['prediction_manifest_version']);
    }

    public function test_v1_and_v2_iteration_contracts_have_different_reproducibility_hashes(): void
    {
        $verifier = new Bt03e03ReproducibilityVerifier(new CanonicalHasher);
        $v2 = $this->resultFixture();
        $v1 = $v2;
        $v1['calculation_version'] = 'BT03E03-POSITION-PROBABILITY-v1';
        $v1['contract']['calculation_version'] = 'BT03E03-POSITION-PROBABILITY-v1';
        $v1['contract']['optimizer_version'] = 'BT03E03-FISTA-POSITION-SOFTMAX-v1';
        $v1['contract']['artifact_version'] = 'BT03E03-DEVELOPMENT-ARTIFACT-v1';
        unset($v1['contract']['iteration_semantics_version']);
        unset($v1['contract']['solver_constants']['max_iterations_semantics']);
        $v1['contract']['solver_constants']['restart_rule'] = 'MONOTONE_OBJECTIVE_RESTART';

        $this->assertNotSame($verifier->hash($v1), $verifier->hash($v2));
    }

    public function test_outer_outcome_access_requires_candidate_freeze(): void
    {
        $audit = new Bt03e02ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordSnapshotYear(2022);
        $audit->recordSnapshotYear(2023);
        try {
            $audit->recordSnapshotYear(2024);
            $this->fail('Outer outcome must remain closed before candidate freeze.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preceded candidate freeze', $exception->getMessage());
        }
        $audit->recordCandidateFrozen(2024);
        $audit->recordSnapshotYear(2024);
        $audit->recordCandidateFrozen(2025);
        $audit->recordSnapshotYear(2025);
        $result = $audit->finish();

        $this->assertSame(0, $result['2026_query_or_binding_count']);
    }

    public function test_reproducibility_pending_verified_and_semantic_mismatch(): void
    {
        $verifier = new Bt03e03ReproducibilityVerifier(new CanonicalHasher);
        $result = $this->resultFixture();
        $hash = $verifier->hash($result);
        $this->assertSame('REPRODUCIBILITY VERIFICATION REQUIRED', $verifier->verify(null, $hash)['status']);
        $result['reproducibility_hash'] = $hash;
        $path = sys_get_temp_dir().'/bt03e03-repro-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        try {
            $this->assertSame('VERIFIED', $verifier->verify($path, $hash)['status']);
            $changed = $result;
            $changed['outer_2024']['probability_metrics']['POSITION_1_LOG_LOSS'] = 9.9;
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('mismatched');
            $verifier->verify($path, $verifier->hash($changed));
        } finally {
            unlink($path);
        }
    }

    public function test_probability_map_and_calibration_all_change_the_result_hash(): void
    {
        $verifier = new Bt03e03ReproducibilityVerifier(new CanonicalHasher);
        $base = $this->resultFixture();
        foreach ([
            ['outer_2024', 'probability_metrics', 'POSITION_1_LOG_LOSS'],
            ['outer_2024', 'map_diagnostics', 'TOP3_SET_MAP_RATE'],
            ['outer_2024', 'calibration', 'positions'],
            ['outer_2024', 'prediction_manifest', 'semantic_sha256'],
        ] as $path) {
            $changed = $base;
            $changed[$path[0]][$path[1]][$path[2]] = ['changed'];
            $this->assertNotSame($verifier->hash($base), $verifier->hash($changed));
        }
    }

    public function test_artifact_streams_required_probability_columns(): void
    {
        $directory = sys_get_temp_dir().'/bt03e03-artifact-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $spools = [
            2024 => new Bt03e02RaceSpool('PREDICTION', $directory.'/predictions-2024.jsonl'),
            2025 => new Bt03e02RaceSpool('PREDICTION', $directory.'/predictions-2025.jsonl'),
        ];
        try {
            $summary = $this->resultFixture();
            foreach ($spools as $year => $spool) {
                $prediction = (new Bt03e03ProbabilityScorer)->predict($this->race($year), $this->fit());
                $manifest = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
                $manifest->append($prediction);
                $summary["outer_{$year}"]['prediction_manifest'] = $manifest->seal();
                $spool->append($prediction);
                $spool->seal();
            }
            $summary['reproducibility_hash'] = (new Bt03e03ReproducibilityVerifier(new CanonicalHasher))->hash($summary);
            $paths = (new Bt03e03ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher))->write(
                $directory,
                $summary,
                $spools,
            );
            $handle = fopen($paths['probabilities_csv'], 'rb');
            $header = $handle === false ? false : fgetcsv($handle, escape: '');
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->assertSame([
                'year', 'race_id', 'bike_number', 'position_1_probability', 'position_2_probability',
                'position_3_probability', 'top2_probability', 'top3_probability', 'predicted_position', 'is_map_top3',
            ], $header);
            $handle = fopen($paths['map_predictions_csv'], 'rb');
            $mapHeader = $handle === false ? false : fgetcsv($handle, escape: '');
            $map = $handle === false ? false : fgetcsv($handle, escape: '');
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->assertSame([
                'year', 'race_id', 'map_ordered_top3', 'map_ordered_probability',
                'map_top3_set', 'map_top3_set_probability',
            ], $mapHeader);
            $this->assertSame('2024', $map[0]);
            $this->assertSame('1', $map[1]);
            $this->assertNotSame('', $map[2]);
            $this->assertNotSame('', $map[4]);
            $this->assertFileExists($paths['manifest_json']);
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            $this->removeDirectory($directory);
        }
    }

    /** @return array<string,mixed> */
    private function resultFixture(): array
    {
        $outer = [
            'lambda_selection' => ['lambda' => 0.1, 'candidate_statuses' => []],
            'model' => ['bins' => [], 'position_coefficients' => []],
            'refit_path' => ['selected_lambda' => 0.1, 'fit_order' => [1.0, 0.1], 'candidate_statuses' => []],
            'metrics' => ['candidate' => [], 'baseline' => [], 'delta' => []],
            'probability_metrics' => ['POSITION_1_LOG_LOSS' => 1.0],
            'calibration' => ['positions' => []],
            'map_diagnostics' => ['TOP3_SET_MAP_RATE' => 0.1],
            'prediction_manifest' => [
                'version' => Bt03e03Contract::PREDICTION_MANIFEST_VERSION,
                'race_count' => 1,
                'entry_count' => 5,
                'semantic_sha256' => str_repeat('a', 64),
            ],
        ];

        return [
            'calculation_version' => Bt03e03Contract::CALCULATION_VERSION,
            'contract' => Bt03e03Contract::plan(),
            'source_integrity' => ['unchanged' => true],
            'outcome_snapshot' => ['unchanged' => true],
            'outer_2024' => $outer,
            'outer_2025' => $outer,
            'paired_bootstrap_ci' => [],
            'acceptance_gate_input' => ['outer_metrics' => [], 'paired_bootstrap_ci' => []],
        ];
    }

    /** @return array<string,mixed> */
    private function race(int $year = 2024): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $bins = array_fill(0, count(Bt03e03Contract::STAT_CODES), null);
            $bins[0] = $bike - 1;
            $entries[] = [
                'id' => $bike,
                'bike' => $bike,
                'raw' => 100.0 - $bike,
                'stat01_rank' => $bike,
                'anchor' => 0.0,
                'bins' => $bins,
                'rank' => $bike,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => $year, 'race_id' => $year - 2023, 'entries' => $entries];
    }

    private function fit(): Bt03e03FitResultDto
    {
        $size = $this->layout()->size();

        return new Bt03e03FitResultDto(
            0.1,
            array_fill_keys(Bt03e03Contract::POSITIONS, array_fill(0, $size, 0.0)),
            array_fill_keys(Bt03e03Contract::POSITIONS, 0.0),
            array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            array_fill_keys(Bt03e03Contract::POSITIONS, 0),
        );
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e03Contract::STAT_CODES as $statCode) {
            foreach (range(1, 5) as $index) {
                $bins[$statCode][] = new EffectBinDto($index, 'CATEGORY', null, null, (string) $index, 1);
            }
        }

        return new Bt03e02ParameterLayout($bins);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            $path = $directory.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
