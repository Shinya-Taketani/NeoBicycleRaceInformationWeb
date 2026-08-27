<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\Bt03e04RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use JsonException;
use RuntimeException;
use Throwable;

final class Bt03e04SourceBundleLoader
{
    private const MAX_JSON_BYTES = 64 * 1024 * 1024;

    private const PROBABILITY_HEADER = [
        'year', 'race_id', 'bike_number', 'position_1_probability', 'position_2_probability',
        'position_3_probability', 'top2_probability', 'top3_probability', 'predicted_position', 'is_map_top3',
    ];

    private const MAP_HEADER = [
        'year', 'race_id', 'map_ordered_top3', 'map_ordered_probability',
        'map_top3_set', 'map_top3_set_probability',
    ];

    public function __construct(
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e03ReproducibilityVerifier $sourceReproducibility,
    ) {}

    /** @return array{identity:array<string,mixed>,source_result:array<string,mixed>,years:array<int,Bt03e04RaceSpool>} */
    public function load(string $directory, string $temporaryDirectory = '/tmp'): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException('BT-03E-04 source bundle directory did not exist.');
        }
        $paths = [];
        foreach (['result.json', 'probabilities.csv', 'map_predictions.csv', 'manifest.json'] as $file) {
            $paths[$file] = rtrim($directory, '/').'/'.$file;
            if (! is_file($paths[$file])) {
                throw new RuntimeException("BT-03E-04 source bundle lacked {$file}.");
            }
        }

        $manifest = $this->jsonFile($paths['manifest.json']);
        $files = $this->validateManifest($manifest, $paths);
        $result = $this->jsonFile($paths['result.json']);
        $this->validateResult($result);

        $spools = [];
        try {
            foreach (Bt03e04Contract::DEVELOPMENT_YEARS as $year) {
                $spools[$year] = new Bt03e04RaceSpool(
                    'SOURCE',
                    rtrim($temporaryDirectory, '/').'/bt03e04-source-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl',
                );
            }
            $counts = $this->parseCsv($paths['probabilities.csv'], $paths['map_predictions.csv'], $spools);
            foreach ($spools as $spool) {
                $spool->seal();
            }
            $this->validatePredictionCounts($result, $counts);
        } catch (Throwable $throwable) {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            throw $throwable;
        }

        return [
            'identity' => [
                'source_reproducibility_hash' => $result['reproducibility_hash'],
                'source_artifact_manifest_sha256' => $manifest['manifest_sha256'],
                'probabilities_csv_sha256' => $files['probabilities.csv']['sha256'],
                'map_predictions_csv_sha256' => $files['map_predictions.csv']['sha256'],
                'outer_2024_prediction_semantic_hash' => $result['outer_2024']['prediction_manifest']['semantic_sha256'],
                'outer_2025_prediction_semantic_hash' => $result['outer_2025']['prediction_manifest']['semantic_sha256'],
            ],
            'source_result' => $result,
            'years' => $spools,
        ];
    }

    /** @param array<string,mixed> $manifest @param array<string,string> $paths @return array<string,array{name:string,bytes:int,sha256:string}> */
    private function validateManifest(array $manifest, array $paths): array
    {
        if (($manifest['artifact_version'] ?? null) !== Bt03e04Contract::SOURCE_ARTIFACT_VERSION
            || ! is_array($manifest['files'] ?? null)
            || ! is_string($manifest['manifest_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $manifest['manifest_sha256']) !== 1) {
            throw new RuntimeException('BT-03E-04 source artifact manifest contract was invalid.');
        }
        $claimedHash = $manifest['manifest_sha256'];
        unset($manifest['manifest_sha256']);
        if (! hash_equals($claimedHash, $this->hasher->hash($manifest))) {
            throw new RuntimeException('BT-03E-04 source artifact manifest SHA-256 mismatched.');
        }
        $files = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || array_keys($file) !== ['name', 'bytes', 'sha256']
                || ! in_array($file['name'] ?? null, ['result.json', 'probabilities.csv', 'map_predictions.csv'], true)
                || isset($files[$file['name']]) || ! is_int($file['bytes']) || $file['bytes'] < 1
                || ! is_string($file['sha256']) || preg_match('/\A[a-f0-9]{64}\z/', $file['sha256']) !== 1) {
                throw new RuntimeException('BT-03E-04 source artifact file manifest was invalid.');
            }
            $actualBytes = filesize($paths[$file['name']]);
            $actualHash = hash_file('sha256', $paths[$file['name']]);
            if ($actualBytes !== $file['bytes'] || ! is_string($actualHash) || ! hash_equals($file['sha256'], $actualHash)) {
                throw new RuntimeException("BT-03E-04 source {$file['name']} bytes or SHA-256 mismatched.");
            }
            $files[$file['name']] = $file;
        }
        if (array_keys($files) !== ['result.json', 'probabilities.csv', 'map_predictions.csv']) {
            throw new RuntimeException('BT-03E-04 source artifact required exactly three manifested files in canonical order.');
        }

        return $files;
    }

    /** @param array<string,mixed> $result */
    private function validateResult(array $result): void
    {
        $contract = $result['contract'] ?? null;
        if (($result['calculation_version'] ?? null) !== Bt03e04Contract::SOURCE_CALCULATION_VERSION
            || ! is_array($contract)
            || ($contract['contract'] ?? null) !== Bt03e04Contract::SOURCE_CONTRACT_NAME
            || ($contract['calculation_version'] ?? null) !== Bt03e04Contract::SOURCE_CALCULATION_VERSION
            || ($contract['optimizer_version'] ?? null) !== Bt03e04Contract::SOURCE_OPTIMIZER_VERSION
            || ($contract['iteration_semantics_version'] ?? null) !== Bt03e04Contract::SOURCE_ITERATION_SEMANTICS_VERSION
            || ($contract['probability_version'] ?? null) !== Bt03e04Contract::SOURCE_PROBABILITY_VERSION
            || ($contract['artifact_version'] ?? null) !== Bt03e04Contract::SOURCE_ARTIFACT_VERSION
            || ($contract['prediction_manifest_version'] ?? null) !== Bt03e04Contract::SOURCE_PREDICTION_MANIFEST_VERSION) {
            throw new RuntimeException('BT-03E-04 rejected a non-v2 source model contract.');
        }
        foreach (Bt03e04Contract::DEVELOPMENT_YEARS as $year) {
            $outer = $result["outer_{$year}"] ?? null;
            if (! is_array($outer)
                || ($outer['model']['optimizer_version'] ?? null) !== Bt03e04Contract::SOURCE_OPTIMIZER_VERSION
                || ($outer['model']['probability_version'] ?? null) !== Bt03e04Contract::SOURCE_PROBABILITY_VERSION
                || ! is_array($outer['prediction_manifest'] ?? null)
                || ($outer['prediction_manifest']['version'] ?? null) !== Bt03e04Contract::SOURCE_PREDICTION_MANIFEST_VERSION
                || ! is_int($outer['prediction_manifest']['race_count'] ?? null)
                || ! is_int($outer['prediction_manifest']['entry_count'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', (string) ($outer['prediction_manifest']['semantic_sha256'] ?? '')) !== 1) {
                throw new RuntimeException("BT-03E-04 source Outer {$year} prediction contract was invalid.");
            }
        }
        $verification = $result['reproducibility_verification'] ?? null;
        if (! is_array($verification) || ($verification['status'] ?? null) !== Bt03e04Contract::SOURCE_REPRODUCIBILITY_STATUS
            || ($verification['verified'] ?? null) !== true
            || ! is_string($result['reproducibility_hash'] ?? null)) {
            throw new RuntimeException('BT-03E-04 source reproducibility was not VERIFIED.');
        }
        $actualReproducibility = $this->sourceReproducibility->hash($result);
        if (! hash_equals($result['reproducibility_hash'], $actualReproducibility)
            || ! hash_equals((string) ($verification['current_hash'] ?? ''), $actualReproducibility)
            || ! hash_equals((string) ($verification['previous_hash'] ?? ''), $actualReproducibility)) {
            throw new RuntimeException('BT-03E-04 source reproducibility hash was invalid.');
        }
        if (($result['acceptance_gate']['gates']['integrity'] ?? null) !== true
            || ($result['source_integrity']['unchanged'] ?? null) !== true
            || ($result['outcome_snapshot']['unchanged'] ?? null) !== true) {
            throw new RuntimeException('BT-03E-04 source integrity was not PASS.');
        }
        if (($result['audit']['2026_access_count'] ?? null) !== 0
            || (isset($result['audit']['2026_query_or_binding_count']) && $result['audit']['2026_query_or_binding_count'] !== 0)) {
            throw new RuntimeException('BT-03E-04 source audit contained 2026 access.');
        }
    }

    /** @param array<int,Bt03e04RaceSpool> $spools @return array<int,array{race_count:int,entry_count:int}> */
    private function parseCsv(string $probabilityPath, string $mapPath, array $spools): array
    {
        $probabilities = fopen($probabilityPath, 'rb');
        $maps = fopen($mapPath, 'rb');
        if ($probabilities === false || $maps === false) {
            if (is_resource($probabilities)) {
                fclose($probabilities);
            }
            if (is_resource($maps)) {
                fclose($maps);
            }
            throw new RuntimeException('BT-03E-04 source CSV could not be opened.');
        }
        $counts = array_fill_keys(Bt03e04Contract::DEVELOPMENT_YEARS, ['race_count' => 0, 'entry_count' => 0]);
        $seen = [];
        try {
            if (fgetcsv($probabilities, escape: '') !== self::PROBABILITY_HEADER
                || fgetcsv($maps, escape: '') !== self::MAP_HEADER) {
                throw new RuntimeException('BT-03E-04 source CSV header was invalid.');
            }
            $probabilityRow = $this->csvRow($probabilities);
            $mapRow = $this->csvRow($maps);
            while ($probabilityRow !== null) {
                [$year, $raceId] = $this->identity($probabilityRow);
                $key = "{$year}:{$raceId}";
                if (isset($seen[$key])) {
                    throw new RuntimeException('BT-03E-04 source probabilities duplicated a race.');
                }
                $seen[$key] = true;
                $entries = [];
                do {
                    $entries[] = $this->probabilityEntry($probabilityRow);
                    $probabilityRow = $this->csvRow($probabilities);
                    $nextIdentity = $probabilityRow !== null ? $this->identity($probabilityRow) : null;
                } while ($nextIdentity === [$year, $raceId]);

                if ($mapRow === null || $this->identity($mapRow) !== [$year, $raceId]) {
                    throw new RuntimeException('BT-03E-04 source probability and MAP race streams differed.');
                }
                $map = $this->map($mapRow, array_column($entries, 'bike'));
                $this->assertProbabilityRace($entries);
                $spools[$year]->append([
                    'year' => $year,
                    'race_id' => $raceId,
                    'entries' => $entries,
                    ...$map,
                ]);
                $counts[$year]['race_count']++;
                $counts[$year]['entry_count'] += count($entries);
                $mapRow = $this->csvRow($maps);
            }
            if ($mapRow !== null) {
                $this->identity($mapRow);
                throw new RuntimeException('BT-03E-04 source MAP stream contained an extra race.');
            }
            if (! feof($probabilities) || ! feof($maps)) {
                throw new RuntimeException('BT-03E-04 source CSV streams did not end together.');
            }
        } finally {
            fclose($probabilities);
            fclose($maps);
        }

        return $counts;
    }

    /** @param list<string|null> $row @return array{0:int,1:int} */
    private function identity(array $row): array
    {
        $year = $this->integer($row[0] ?? null, 'year');
        $raceId = $this->integer($row[1] ?? null, 'race_id');
        if (! in_array($year, Bt03e04Contract::DEVELOPMENT_YEARS, true)) {
            throw new RuntimeException($year === 2026
                ? 'BT-03E-04 source CSV contained forbidden 2026 data.'
                : 'BT-03E-04 source CSV year was outside 2024/2025.');
        }

        return [$year, $raceId];
    }

    /** @param list<string|null> $row @return array<string,mixed> */
    private function probabilityEntry(array $row): array
    {
        if (count($row) !== count(self::PROBABILITY_HEADER)) {
            throw new RuntimeException('BT-03E-04 probability CSV row width was invalid.');
        }

        return [
            'bike' => $this->integer($row[2], 'bike_number'),
            'position_1_probability' => $this->probability($row[3], 'position_1_probability'),
            'position_2_probability' => $this->probability($row[4], 'position_2_probability'),
            'position_3_probability' => $this->probability($row[5], 'position_3_probability'),
            'top2_probability' => $this->probability($row[6], 'top2_probability'),
            'top3_probability' => $this->probability($row[7], 'top3_probability'),
            'source_predicted_position' => $this->integer($row[8], 'predicted_position'),
            'source_is_map_top3' => match ($row[9]) {
                '0' => false,
                '1' => true,
                default => throw new RuntimeException('BT-03E-04 source is_map_top3 was invalid.'),
            },
        ];
    }

    /** @param list<string|null> $row @param list<int> $entrants @return array<string,mixed> */
    private function map(array $row, array $entrants): array
    {
        if (count($row) !== count(self::MAP_HEADER)) {
            throw new RuntimeException('BT-03E-04 MAP CSV row width was invalid.');
        }
        $ordered = $this->bikeTriple($row[2], 'map_ordered_top3', $entrants);
        $set = $this->bikeTriple($row[4], 'map_top3_set', $entrants);
        sort($set, SORT_NUMERIC);

        return [
            'map_ordered_top3' => $ordered,
            'map_ordered_probability' => $this->probability($row[3], 'map_ordered_probability'),
            'map_top3_set' => $set,
            'map_top3_set_probability' => $this->probability($row[5], 'map_top3_set_probability'),
        ];
    }

    /** @param list<array<string,mixed>> $entries */
    private function assertProbabilityRace(array $entries): void
    {
        if (count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('BT-03E-04 source entrant count was outside 5-9.');
        }
        $bikes = array_column($entries, 'bike');
        if (count(array_unique($bikes)) !== count($bikes)
            || array_filter($bikes, static fn (int $bike): bool => $bike < 1 || $bike > 9) !== []) {
            throw new RuntimeException('BT-03E-04 source bike numbers were invalid.');
        }
        foreach ([1, 2, 3] as $position) {
            $sum = array_sum(array_column($entries, "position_{$position}_probability"));
            if (abs($sum - 1.0) > Bt03e04Contract::PROBABILITY_TOLERANCE) {
                throw new RuntimeException("BT-03E-04 source P{$position} sum invariant failed.");
            }
        }
        foreach ($entries as $entry) {
            $top2 = $entry['position_1_probability'] + $entry['position_2_probability'];
            $top3 = $top2 + $entry['position_3_probability'];
            if (abs($entry['top2_probability'] - $top2) > Bt03e04Contract::PROBABILITY_TOLERANCE
                || abs($entry['top3_probability'] - $top3) > Bt03e04Contract::PROBABILITY_TOLERANCE
                || $entry['top2_probability'] > 1.0 + Bt03e04Contract::PROBABILITY_TOLERANCE
                || $entry['top3_probability'] > 1.0 + Bt03e04Contract::PROBABILITY_TOLERANCE) {
                throw new RuntimeException('BT-03E-04 source marginal probability invariant failed.');
            }
        }
    }

    /** @param array<string,mixed> $result @param array<int,array{race_count:int,entry_count:int}> $counts */
    private function validatePredictionCounts(array $result, array $counts): void
    {
        foreach (Bt03e04Contract::DEVELOPMENT_YEARS as $year) {
            $manifest = $result["outer_{$year}"]['prediction_manifest'];
            if ($counts[$year]['race_count'] !== $manifest['race_count']
                || $counts[$year]['entry_count'] !== $manifest['entry_count']) {
                throw new RuntimeException("BT-03E-04 source Outer {$year} CSV counts mismatched its prediction manifest.");
            }
        }
    }

    /** @param resource $handle @return list<string|null>|null */
    private function csvRow($handle): ?array
    {
        $row = fgetcsv($handle, escape: '');
        if ($row === false) {
            return null;
        }
        if ($row === [null]) {
            throw new RuntimeException('BT-03E-04 source CSV contained an empty row.');
        }

        return $row;
    }

    private function integer(?string $value, string $field): int
    {
        if ($value === null || preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
            throw new RuntimeException("BT-03E-04 source {$field} was invalid.");
        }

        return (int) $value;
    }

    private function probability(?string $value, string $field): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new RuntimeException("BT-03E-04 source {$field} was invalid.");
        }
        $probability = (float) $value;
        if (! is_finite($probability) || $probability < 0.0 || $probability > 1.0) {
            throw new RuntimeException("BT-03E-04 source {$field} was outside [0,1].");
        }

        return $probability;
    }

    /** @param list<int> $entrants @return list<int> */
    private function bikeTriple(?string $value, string $field, array $entrants): array
    {
        if ($value === null || preg_match('/\A([1-9])-([1-9])-([1-9])\z/', $value, $matches) !== 1) {
            throw new RuntimeException("BT-03E-04 source {$field} was invalid.");
        }
        $bikes = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        if (count(array_unique($bikes)) !== 3 || array_diff($bikes, $entrants) !== []) {
            throw new RuntimeException("BT-03E-04 source {$field} entrants were invalid.");
        }

        return $bikes;
    }

    /** @return array<string,mixed> */
    private function jsonFile(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_JSON_BYTES) {
            throw new RuntimeException('BT-03E-04 source JSON size was invalid.');
        }
        $contents = file_get_contents($path);
        try {
            $value = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new RuntimeException('BT-03E-04 source JSON was invalid.', previous: $exception);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('BT-03E-04 source JSON root was invalid.');
        }

        return $value;
    }
}
