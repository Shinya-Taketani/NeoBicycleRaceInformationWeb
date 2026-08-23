<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e02ArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03e02ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e02SourceIntegrityGuard;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03e02IntegrityTest extends TestCase
{
    public function test_source_drift_fails_closed(): void
    {
        $guard = new Bt03e02SourceIntegrityGuard;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('feature source fingerprints drifted');

        $guard->assertUnchanged(['digest' => str_repeat('a', 64)], ['digest' => str_repeat('b', 64)], 'feature source fingerprints');
    }

    public function test_failed_publication_removes_the_temporary_bundle(): void
    {
        $directory = sys_get_temp_dir().'/bt03e02-artifact-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $filesystem = new class extends Bt03eArtifactFilesystem
        {
            public function publish(string $temporaryDirectory, string $finalDirectory): void
            {
                throw new RuntimeException('synthetic publication failure');
            }
        };

        try {
            $writer = new Bt03e02ArtifactWriter($filesystem, new CanonicalHasher);
            try {
                $writer->write($directory, ['run_identity' => 'synthetic', 'reproducibility_hash' => str_repeat('a', 64)]);
                $this->fail('Publication must fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('synthetic publication failure', $exception->getMessage());
            }

            $this->assertSame([], array_values(array_diff(scandir($directory) ?: [], ['.', '..'])));
        } finally {
            rmdir($directory);
        }
    }

    public function test_reproducibility_hash_excludes_run_identity_and_runtime(): void
    {
        $verifier = new Bt03e02ReproducibilityVerifier(new CanonicalHasher);
        $first = $this->resultFixture('run-1', 1.0);
        $second = $this->resultFixture('run-2', 2.0);

        $this->assertSame($verifier->hash($first), $verifier->hash($second));
    }

    public function test_first_execution_requires_reproducibility_verification(): void
    {
        $verifier = new Bt03e02ReproducibilityVerifier(new CanonicalHasher);
        $result = $verifier->verify(null, $verifier->hash($this->resultFixture('run-1', 1.0)));

        $this->assertFalse($result['verified']);
        $this->assertSame('REPRODUCIBILITY VERIFICATION REQUIRED', $result['status']);
    }

    public function test_same_deterministic_artifact_verifies_successfully(): void
    {
        $verifier = new Bt03e02ReproducibilityVerifier(new CanonicalHasher);
        $previous = $this->resultFixture('run-1', 1.0);
        $previous['reproducibility_hash'] = $verifier->hash($previous);
        $path = $this->writeResult($previous);
        try {
            $current = $this->resultFixture('run-2', 2.0);
            $verification = $verifier->verify($path, $verifier->hash($current));

            $this->assertTrue($verification['verified']);
            $this->assertSame('VERIFIED', $verification['status']);
        } finally {
            unlink($path);
        }
    }

    public function test_model_or_evaluation_change_fails_reproducibility_verification(): void
    {
        $verifier = new Bt03e02ReproducibilityVerifier(new CanonicalHasher);
        $previous = $this->resultFixture('run-1', 1.0);
        $previous['reproducibility_hash'] = $verifier->hash($previous);
        $path = $this->writeResult($previous);
        $current = $this->resultFixture('run-2', 2.0);
        $current['outer_2025']['model']['coefficients'][0] = 0.75;

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('reproducibility verification mismatched');
            $verifier->verify($path, $verifier->hash($current));
        } finally {
            unlink($path);
        }
    }

    public function test_outer_refit_path_audit_is_part_of_the_reproducibility_hash(): void
    {
        $verifier = new Bt03e02ReproducibilityVerifier(new CanonicalHasher);
        $first = $this->resultFixture('run-1', 1.0);
        $second = $first;
        $second['outer_2024']['refit_path']['fit_order'] = [1.0, 0.1];

        $this->assertSame(
            ['selected_lambda', 'fit_order', 'candidate_statuses'],
            array_keys($first['outer_2024']['refit_path']),
        );
        $this->assertNotSame($verifier->hash($first), $verifier->hash($second));
    }

    /** @return array<string,mixed> */
    private function resultFixture(string $runIdentity, float $runtime): array
    {
        $outer = [
            'lambda_selection' => ['lambda' => 0.01],
            'alpha_selection' => ['alpha' => ['IS_WIN' => 1.0, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0]],
            'model' => ['bins' => [['index' => 1]], 'support' => [10], 'coefficients' => [0.5], 'scales' => [1.2]],
            'refit_path' => [
                'selected_lambda' => 0.01,
                'fit_order' => [1.0, 0.1, 0.01],
                'candidate_statuses' => [
                    '0.01' => ['status' => 'CONVERGED', 'warm_start_from_lambda' => 0.1],
                    '0.10000000000000001' => ['status' => 'CONVERGED', 'warm_start_from_lambda' => 1.0],
                    '1' => ['status' => 'CONVERGED', 'warm_start_from_lambda' => null],
                ],
            ],
            'metrics' => ['candidate' => ['WINNER_HIT_AT_1' => 0.4], 'baseline' => ['WINNER_HIT_AT_1' => 0.3]],
        ];

        return [
            'run_identity' => $runIdentity,
            'runtime' => ['seconds' => $runtime],
            'calculation_version' => 'test-v1',
            'contract' => ['name' => 'BT-03E-02'],
            'source_integrity' => ['unchanged' => true],
            'outcome_snapshot' => ['unchanged' => true],
            'outer_2024' => $outer,
            'outer_2025' => $outer,
            'paired_bootstrap_ci' => ['WINNER_HIT_AT_1' => ['ci_lower' => 0.0, 'ci_upper' => 0.1]],
        ];
    }

    /** @param array<string,mixed> $result */
    private function writeResult(array $result): string
    {
        $path = sys_get_temp_dir().'/bt03e02-previous-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return $path;
    }
}
