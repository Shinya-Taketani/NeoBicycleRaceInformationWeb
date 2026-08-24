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
        $spool = new Bt03e02RaceSpool('PREDICTION', $directory.'/predictions.jsonl');
        try {
            $prediction = (new Bt03e03ProbabilityScorer)->predict($this->race(), $this->fit());
            $spool->append($prediction);
            $spool->seal();
            $summary = $this->resultFixture();
            $summary['reproducibility_hash'] = (new Bt03e03ReproducibilityVerifier(new CanonicalHasher))->hash($summary);
            $paths = (new Bt03e03ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher))->write(
                $directory,
                $summary,
                [2024 => $spool],
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
            $spool->cleanup();
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
    private function race(): array
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

        return ['year' => 2024, 'race_id' => 1, 'entries' => $entries];
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
