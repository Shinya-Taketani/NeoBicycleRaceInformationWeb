<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e06ReproducibilityVerifier
{
    private const MAX_ARTIFACT_BYTES = 64 * 1024 * 1024;

    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @param array<string,mixed> $result */
    public function hash(array $result): string
    {
        $payload = [];
        foreach ([
            'calculation_version', 'contract', 'source_bundle_identity', 'outer_model_canonical_hashes',
            'feature_source_integrity', 'outcome_snapshot_identity', 'reconstruction_manifests',
            'decoder_manifests', 'outer_2024', 'outer_2025', 'paired_bootstrap_ci', 'acceptance_gate_input',
        ] as $key) {
            if (! array_key_exists($key, $result)) {
                throw new RuntimeException("BT-03E-06 reproducibility payload lacked {$key}.");
            }
            $payload[$key] = $result[$key];
        }

        return $this->hasher->hash($payload);
    }

    /** @return array{status:string,verified:bool,previous_artifact:?string,previous_hash:?string,current_hash:string} */
    public function verify(?string $previousArtifact, string $currentHash): array
    {
        if ($previousArtifact === null) {
            return [
                'status' => 'REPRODUCIBILITY VERIFICATION REQUIRED',
                'verified' => false,
                'previous_artifact' => null,
                'previous_hash' => null,
                'current_hash' => $currentHash,
            ];
        }
        if (! is_file($previousArtifact)) {
            throw new RuntimeException('BT-03E-06 reproducibility artifact did not exist.');
        }
        $size = filesize($previousArtifact);
        if ($size === false || $size < 1 || $size > self::MAX_ARTIFACT_BYTES) {
            throw new RuntimeException('BT-03E-06 reproducibility artifact size was invalid.');
        }
        $json = file_get_contents($previousArtifact);
        $previous = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($previous) || ! is_string($previous['reproducibility_hash'] ?? null)) {
            throw new RuntimeException('BT-03E-06 reproducibility artifact was invalid.');
        }
        $previousHash = $this->hash($previous);
        if (! hash_equals($previousHash, $previous['reproducibility_hash']) || ! hash_equals($previousHash, $currentHash)) {
            throw new RuntimeException('BT-03E-06 reproducibility verification mismatched.');
        }

        return [
            'status' => 'VERIFIED',
            'verified' => true,
            'previous_artifact' => $previousArtifact,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
        ];
    }
}
