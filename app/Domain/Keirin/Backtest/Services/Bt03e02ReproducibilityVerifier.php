<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e02ReproducibilityVerifier
{
    private const MAX_ARTIFACT_BYTES = 64 * 1024 * 1024;

    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @param array<string,mixed> $result */
    public function hash(array $result): string
    {
        $payload = [];
        foreach ([
            'calculation_version',
            'contract',
            'source_integrity',
            'outcome_snapshot',
            'outer_2024',
            'outer_2025',
            'paired_bootstrap_ci',
        ] as $key) {
            if (! array_key_exists($key, $result)) {
                throw new RuntimeException("BT-03E-02 reproducibility payload lacked {$key}.");
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
            throw new RuntimeException('BT-03E-02 reproducibility artifact did not exist.');
        }
        $size = filesize($previousArtifact);
        if ($size === false || $size < 1 || $size > self::MAX_ARTIFACT_BYTES) {
            throw new RuntimeException('BT-03E-02 reproducibility artifact size was invalid.');
        }
        $json = file_get_contents($previousArtifact);
        $previous = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($previous) || ! is_string($previous['reproducibility_hash'] ?? null)) {
            throw new RuntimeException('BT-03E-02 reproducibility artifact was invalid.');
        }
        $computedPreviousHash = $this->hash($previous);
        if (! hash_equals($computedPreviousHash, $previous['reproducibility_hash'])) {
            throw new RuntimeException('BT-03E-02 previous artifact reproducibility hash was invalid.');
        }
        if (! hash_equals($computedPreviousHash, $currentHash)) {
            throw new RuntimeException('BT-03E-02 reproducibility verification mismatched.');
        }

        return [
            'status' => 'VERIFIED',
            'verified' => true,
            'previous_artifact' => $previousArtifact,
            'previous_hash' => $computedPreviousHash,
            'current_hash' => $currentHash,
        ];
    }
}
