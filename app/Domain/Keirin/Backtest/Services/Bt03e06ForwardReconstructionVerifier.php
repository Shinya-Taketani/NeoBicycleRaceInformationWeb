<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use RuntimeException;

final class Bt03e06ForwardReconstructionVerifier
{
    /** @param array<string,mixed> $source @param array<string,mixed> $reconstructed */
    public function verifyRace(array $source, array $reconstructed): void
    {
        if (($source['year'] ?? null) !== ($reconstructed['year'] ?? null)
            || ($source['race_id'] ?? null) !== ($reconstructed['race_id'] ?? null)
            || ! is_array($source['entries'] ?? null) || ! is_array($reconstructed['entries'] ?? null)
            || count($source['entries']) !== count($reconstructed['entries'])) {
            throw new RuntimeException('BT-03E-06 forward reconstruction race identity differed.');
        }
        foreach ($source['entries'] as $offset => $sourceEntry) {
            $actual = $reconstructed['entries'][$offset] ?? null;
            if (! is_array($sourceEntry) || ! is_array($actual)
                || ($sourceEntry['bike'] ?? null) !== ($actual['bike'] ?? null)) {
                throw new RuntimeException('BT-03E-06 forward reconstruction entrant order differed.');
            }
            foreach ([
                'position_1_probability', 'position_2_probability', 'position_3_probability',
                'top2_probability', 'top3_probability',
            ] as $field) {
                if (! array_key_exists($field, $sourceEntry) || ! array_key_exists($field, $actual)
                    || $sourceEntry[$field] !== $actual[$field]) {
                    throw new RuntimeException("BT-03E-06 forward reconstruction {$field} differed.");
                }
            }
            if (($sourceEntry['source_predicted_position'] ?? null) !== ($actual['predicted_position'] ?? null)
                || ($sourceEntry['source_is_map_top3'] ?? null) !== ($actual['is_map_top3'] ?? null)) {
                throw new RuntimeException('BT-03E-06 forward reconstruction ranking output differed.');
            }
        }
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability'] as $field) {
            if (! array_key_exists($field, $source) || ! array_key_exists($field, $reconstructed)
                || $source[$field] !== $reconstructed[$field]) {
                throw new RuntimeException("BT-03E-06 forward reconstruction {$field} differed.");
            }
        }
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    public function verifyManifest(array $expected, array $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('BT-03E-06 reconstructed E03 prediction manifest differed from the verified source.');
        }
    }
}
