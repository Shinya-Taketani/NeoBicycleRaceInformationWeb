<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

final class Bt03e03PredictionManifestAccumulator
{
    private ?\HashContext $hash;

    private int $raceCount = 0;

    private int $entryCount = 0;

    private ?int $manifestYear = null;

    /** @var array{version:string,race_count:int,entry_count:int,semantic_sha256:string}|null */
    private ?array $manifest = null;

    public function __construct(private readonly CanonicalHasher $hasher)
    {
        $this->hash = hash_init('sha256');
        hash_update($this->hash, Bt03e03Contract::PREDICTION_MANIFEST_VERSION."\n");
    }

    /** @param array<string,mixed> $race */
    public function append(array $race): void
    {
        $this->assertWritable();
        $year = $race['year'] ?? null;
        $raceId = $race['race_id'] ?? null;
        $entries = $race['entries'] ?? null;
        if (! is_int($year) || ! in_array($year, Bt03e03Contract::OUTER_YEARS, true)
            || ! is_int($raceId) || $raceId < 1 || ! is_array($entries) || $entries === []) {
            throw new RuntimeException('BT-03E-03 prediction manifest race was invalid.');
        }
        if ($this->manifestYear !== null && $year !== $this->manifestYear) {
            throw new RuntimeException('BT-03E-03 prediction manifest mixed Outer years.');
        }

        $first = $entries[0] ?? null;
        if (! is_array($first)) {
            throw new RuntimeException('BT-03E-03 prediction manifest first entry was invalid.');
        }
        $mapOrdered = $this->raceValue($race, $first, 'map_ordered_top3');
        $mapOrderedProbability = $this->probability($this->raceValue($race, $first, 'map_ordered_probability'));
        $mapSet = $this->raceValue($race, $first, 'map_top3_set');
        $mapSetProbability = $this->probability($this->raceValue($race, $first, 'map_top3_set_probability'));
        $mapTieDiagnostics = $this->raceValue($race, $first, 'map_tie_diagnostics');
        if (! is_array($mapOrdered) || count($mapOrdered) !== 3 || count(array_unique($mapOrdered)) !== 3
            || array_filter($mapOrdered, 'is_int') !== $mapOrdered
            || ! is_array($mapSet) || count($mapSet) !== 3 || count(array_unique($mapSet)) !== 3
            || array_filter($mapSet, 'is_int') !== $mapSet
            || ! is_array($mapTieDiagnostics)) {
            throw new RuntimeException('BT-03E-03 prediction manifest MAP payload was invalid.');
        }
        sort($mapSet, SORT_NUMERIC);

        $semanticEntries = [];
        $seenBikes = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('BT-03E-03 prediction manifest entry was invalid.');
            }
            $bike = $entry['bike'] ?? null;
            $predictedPosition = $entry['predicted_position'] ?? null;
            $isMapTop3 = $entry['is_map_top3'] ?? null;
            if (! is_int($bike) || $bike < 1 || $bike > 9 || isset($seenBikes[$bike])
                || ! is_int($predictedPosition) || $predictedPosition < 1 || $predictedPosition > count($entries)
                || ! is_bool($isMapTop3)) {
                throw new RuntimeException('BT-03E-03 prediction manifest entry identity was invalid.');
            }
            $seenBikes[$bike] = true;
            foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'map_tie_diagnostics'] as $key) {
                if (! array_key_exists($key, $entry)
                    || ! hash_equals($this->hasher->hash($first[$key]), $this->hasher->hash($entry[$key]))) {
                    throw new RuntimeException('BT-03E-03 prediction manifest repeated MAP payload drifted.');
                }
            }
            $semanticEntries[] = [
                'bike_number' => $bike,
                'position_1_probability' => $this->probability($entry['position_1_probability'] ?? null),
                'position_2_probability' => $this->probability($entry['position_2_probability'] ?? null),
                'position_3_probability' => $this->probability($entry['position_3_probability'] ?? null),
                'top2_probability' => $this->probability($entry['top2_probability'] ?? null),
                'top3_probability' => $this->probability($entry['top3_probability'] ?? null),
                'predicted_position' => $predictedPosition,
                'is_map_top3' => $isMapTop3,
            ];
        }
        usort($semanticEntries, static fn (array $left, array $right): int => $left['bike_number'] <=> $right['bike_number']);
        $raceHash = $this->hasher->hash([
            'year' => $year,
            'race_id' => $raceId,
            'map_ordered_top3' => $mapOrdered,
            'map_ordered_probability' => $mapOrderedProbability,
            'map_top3_set' => $mapSet,
            'map_top3_set_probability' => $mapSetProbability,
            'map_tie_diagnostics' => $mapTieDiagnostics,
            'entries' => $semanticEntries,
        ]);
        hash_update($this->hash, "{$year}|{$raceId}|{$raceHash}\n");
        $this->raceCount++;
        $this->entryCount += count($semanticEntries);
        $this->manifestYear = $year;
    }

    /** @return array{version:string,race_count:int,entry_count:int,semantic_sha256:string} */
    public function seal(): array
    {
        $this->assertWritable();
        if ($this->raceCount === 0 || $this->entryCount === 0) {
            throw new RuntimeException('BT-03E-03 prediction manifest could not be sealed without predictions.');
        }
        hash_update($this->hash, "COUNTS|{$this->raceCount}|{$this->entryCount}\n");
        $this->manifest = [
            'version' => Bt03e03Contract::PREDICTION_MANIFEST_VERSION,
            'race_count' => $this->raceCount,
            'entry_count' => $this->entryCount,
            'semantic_sha256' => hash_final($this->hash),
        ];
        $this->hash = null;

        return $this->manifest;
    }

    private function raceValue(array $race, array $firstEntry, string $key): mixed
    {
        $value = $race[$key] ?? $firstEntry[$key] ?? null;
        if (! array_key_exists($key, $firstEntry)
            || ! hash_equals($this->hasher->hash($value), $this->hasher->hash($firstEntry[$key]))) {
            throw new RuntimeException("BT-03E-03 prediction manifest {$key} was missing or inconsistent.");
        }

        return $value;
    }

    private function probability(mixed $value): float
    {
        if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)
            || (float) $value < 0.0 || (float) $value > 1.0) {
            throw new RuntimeException('BT-03E-03 prediction manifest probability was invalid.');
        }

        return (float) $value;
    }

    private function assertWritable(): void
    {
        if ($this->manifest !== null || $this->hash === null) {
            throw new RuntimeException('BT-03E-03 prediction manifest accumulator was not writable.');
        }
    }
}
