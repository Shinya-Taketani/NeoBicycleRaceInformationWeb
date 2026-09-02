<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use RuntimeException;

final class Bt03e08FrozenP1Q2SourceAssembler
{
    public function __construct(private readonly Bt03e06ForwardReconstructionVerifier $verifier) {}

    /** @param array<string,mixed> $source @param array<string,mixed> $reconstructed @return array<string,mixed> */
    public function assemble(array $source, array $reconstructed): array
    {
        $this->verifier->verifyRace($source, $reconstructed);

        $authoritative = $source;
        foreach ($authoritative['entries'] as $offset => &$entry) {
            $utilities = $reconstructed['entries'][$offset]['utilities'] ?? null;
            if (! is_array($utilities)
                || array_keys($utilities) !== ['POSITION_1', 'POSITION_2', 'POSITION_3']
                || array_filter($utilities, static fn (mixed $value): bool => ! is_float($value) || ! is_finite($value)) !== []) {
                throw new RuntimeException('BT-03E-08 verified reconstructed utilities were invalid.');
            }
            $entry['utilities'] = $utilities;
        }
        unset($entry);

        return $authoritative;
    }
}
