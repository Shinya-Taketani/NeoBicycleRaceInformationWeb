<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07P1FrozenDecisionDecoder;
use App\Domain\Keirin\Backtest\DTO\Bt03e07FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e07ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Support\Bt03e07PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Bt03e07IntegrityTest extends TestCase
{
    public function test_prediction_manifest_rejects_any_outcome_field(): void
    {
        $hasher = new CanonicalHasher;
        $manifest = new Bt03e07PredictionManifestAccumulator(2024, ['source' => str_repeat('a', 64)], $hasher);
        $decision = $this->decision($hasher);
        $decision['official_rank'] = 1;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('official outcome data');
        $manifest->append($decision);
    }

    public function test_reproducibility_hash_ignores_runtime_and_detects_model_or_prediction_drift(): void
    {
        $verifier = new Bt03e07ReproducibilityVerifier(new CanonicalHasher);
        $first = $this->summary();
        $second = [...$first, 'runtime' => ['seconds' => 99.0], 'run_identity' => 'different'];
        $this->assertSame($verifier->hash($first), $verifier->hash($second));

        $second['outer_2024']['model']['lambda'] = 1.0;
        $this->assertNotSame($verifier->hash($first), $verifier->hash($second));
        $second = $first;
        $second['prediction_manifests'][2024]['semantic_sha256'] = str_repeat('f', 64);
        $this->assertNotSame($verifier->hash($first), $verifier->hash($second));
    }

    public function test_reproducibility_is_pending_first_and_verified_against_an_identical_previous_result(): void
    {
        $verifier = new Bt03e07ReproducibilityVerifier(new CanonicalHasher);
        $result = $this->summary();
        $hash = $verifier->hash($result);
        $this->assertSame('REPRODUCIBILITY VERIFICATION REQUIRED', $verifier->verify(null, $hash)['status']);

        $path = sys_get_temp_dir().'/bt03e07-repro-'.bin2hex(random_bytes(8)).'.json';
        $result['reproducibility_hash'] = $hash;
        file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
        try {
            $verification = $verifier->verify($path, $hash);
            $this->assertTrue($verification['verified']);
            $this->assertSame('VERIFIED', $verification['status']);
        } finally {
            unlink($path);
        }
    }

    /** @return array<string,mixed> */
    private function decision(CanonicalHasher $hasher): array
    {
        $race = ['year' => 2024, 'race_id' => 1, 'entries' => []];
        $sourceEntries = [];
        foreach (range(1, 5) as $bike) {
            $race['entries'][] = ['id' => $bike, 'bike' => $bike, 'anchor' => 0.0, 'bins' => []];
            $p1 = $bike === 1 ? 0.4 : 0.15;
            $sourceEntries[] = ['bike' => $bike, 'position_1_probability' => $p1, 'position_2_probability' => 0.2, 'position_3_probability' => 0.2, 'top2_probability' => $p1 + 0.2, 'top3_probability' => $p1 + 0.4];
        }
        $fit = new Bt03e07FitResultDto(0.0, ['POSITION_2' => [], 'POSITION_3' => []], [], [], [], [], []);
        $direct = (new Bt03e07DirectPositionScorer($hasher))->predict($race, $fit);

        return (new Bt03e07P1FrozenDecisionDecoder)->decode(['year' => 2024, 'race_id' => 1, 'entries' => $sourceEntries, 'map_ordered_top3' => [1, 2, 3], 'map_ordered_probability' => 0.1, 'map_top3_set' => [1, 2, 3], 'map_top3_set_probability' => 0.2], $direct);
    }

    /** @return array<string,mixed> */
    private function summary(): array
    {
        $manifest = ['version' => 'v', 'semantic_sha256' => str_repeat('a', 64)];

        return [
            'calculation_version' => 'v', 'contract' => [], 'source_bundle_identity' => [],
            'feature_source_integrity' => [], 'outcome_snapshot_identity' => [], 'fold_definitions' => [],
            'inner_layout_identities' => [], 'outer_2024' => ['model' => ['lambda' => 0.1]],
            'outer_2025' => ['model' => ['lambda' => 0.1]],
            'prediction_manifests' => [2024 => $manifest, 2025 => $manifest],
            'paired_bootstrap_ci' => [], 'acceptance_gate_input' => [],
        ];
    }
}
