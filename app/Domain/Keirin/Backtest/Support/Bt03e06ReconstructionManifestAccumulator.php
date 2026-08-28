<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use RuntimeException;

final class Bt03e06ReconstructionManifestAccumulator
{
    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @param array<string,mixed> $source @param array<string,mixed> $reconstructed @return array<string,mixed> */
    public function seal(
        int $year,
        string $modelHash,
        string $featureFingerprintDigest,
        array $source,
        array $reconstructed,
    ): array {
        if (! in_array($year, Bt03e06Contract::DEVELOPMENT_YEARS, true)
            || ! $this->sha($modelHash) || ! $this->sha($featureFingerprintDigest)
            || ($source['version'] ?? null) !== Bt03e06Contract::SOURCE_PREDICTION_MANIFEST_VERSION
            || $source !== $reconstructed) {
            throw new RuntimeException('BT-03E-06 reconstruction manifest evidence was invalid.');
        }
        $manifest = [
            'version' => Bt03e06Contract::RECONSTRUCTION_MANIFEST_VERSION,
            'reconstruction_version' => Bt03e06Contract::FORWARD_RECONSTRUCTION_VERSION,
            'year' => $year,
            'source_model_canonical_sha256' => $modelHash,
            'source_prediction_manifest_sha256' => $source['semantic_sha256'],
            'reconstructed_prediction_manifest_sha256' => $reconstructed['semantic_sha256'],
            'fixed_feature_fingerprint_digest' => $featureFingerprintDigest,
            'race_count' => $reconstructed['race_count'],
            'entry_count' => $reconstructed['entry_count'],
            'full_forward_verified' => true,
        ];

        return [...$manifest, 'semantic_sha256' => $this->hasher->hash($manifest)];
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
