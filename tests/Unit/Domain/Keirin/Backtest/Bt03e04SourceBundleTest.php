<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e03ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e04SourceBundleLoader;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Bt03e04SyntheticBundle;

class Bt03e04SourceBundleTest extends TestCase
{
    public function test_valid_verified_v2_bundle_is_streamed_and_pinned(): void
    {
        $bundle = $this->bundle();
        try {
            $loaded = $this->loader()->load($bundle->directory);

            $this->assertSame([2024, 2025], array_keys($loaded['years']));
            $this->assertSame(1, $loaded['years'][2024]->metadata()['race_count']);
            $this->assertSame(5, $loaded['years'][2024]->metadata()['entry_count']);
            $this->assertSame(101, iterator_to_array($loaded['years'][2024]->races())[0]['race_id']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $loaded['identity']['source_reproducibility_hash']);
        } finally {
            foreach ($loaded['years'] ?? [] as $spool) {
                $spool->cleanup();
            }
            $bundle->cleanup();
        }
    }

    #[DataProvider('tamperedFileProvider')]
    public function test_any_manifested_file_tampering_fails_closed(string $file): void
    {
        $bundle = $this->bundle();
        file_put_contents($bundle->directory.'/'.$file, 'x', FILE_APPEND);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('bytes or SHA-256 mismatched');
            $this->loader()->load($bundle->directory);
        } finally {
            $bundle->cleanup();
        }
    }

    /** @return iterable<string,array{string}> */
    public static function tamperedFileProvider(): iterable
    {
        yield 'result' => ['result.json'];
        yield 'probabilities' => ['probabilities.csv'];
        yield 'map' => ['map_predictions.csv'];
    }

    public function test_manifest_hash_and_bytes_mismatch_fail_closed(): void
    {
        $bundle = $this->bundle();
        $manifestPath = $bundle->directory.'/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['manifest_sha256'] = str_repeat('0', 64);
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('manifest SHA-256 mismatched');
            $this->loader()->load($bundle->directory);
        } finally {
            $bundle->cleanup();
        }
    }

    #[DataProvider('invalidResultProvider')]
    public function test_invalid_source_contracts_fail_closed(callable $mutator, string $message): void
    {
        $bundle = $this->bundle();
        $bundle->mutateResult($mutator);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($message);
            $this->loader()->load($bundle->directory);
        } finally {
            $bundle->cleanup();
        }
    }

    /** @return iterable<string,array{callable(array<string,mixed>):array<string,mixed>,string}> */
    public static function invalidResultProvider(): iterable
    {
        yield 'v1' => [static function (array $result): array {
            $result['contract']['artifact_version'] = 'BT03E03-DEVELOPMENT-ARTIFACT-v1';

            return $result;
        }, 'non-v2'];
        yield 'not verified' => [static function (array $result): array {
            $result['reproducibility_verification']['status'] = 'REPRODUCIBILITY VERIFICATION REQUIRED';
            $result['reproducibility_verification']['verified'] = false;

            return $result;
        }, 'not VERIFIED'];
        yield 'integrity false' => [static function (array $result): array {
            $result['acceptance_gate']['gates']['integrity'] = false;

            return $result;
        }, 'integrity was not PASS'];
        yield '2026 access' => [static function (array $result): array {
            $result['audit']['2026_access_count'] = 1;

            return $result;
        }, '2026 access'];
    }

    public function test_2026_probability_row_is_rejected_even_with_a_resealed_manifest(): void
    {
        $bundle = $this->bundle();
        file_put_contents($bundle->directory.'/probabilities.csv', "2026,999,1,0.2,0.2,0.2,0.4,0.6,1,1\n", FILE_APPEND);
        $bundle->sealManifest();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('forbidden 2026');
            $this->loader()->load($bundle->directory);
        } finally {
            $bundle->cleanup();
        }
    }

    public function test_2026_map_row_is_rejected_even_with_a_resealed_manifest(): void
    {
        $bundle = $this->bundle();
        file_put_contents($bundle->directory.'/map_predictions.csv', "2026,999,1-2-3,0.02,1-2-3,0.12\n", FILE_APPEND);
        $bundle->sealManifest();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('forbidden 2026');
            $this->loader()->load($bundle->directory);
        } finally {
            $bundle->cleanup();
        }
    }

    public function test_map_ordered_and_map_set_may_have_different_bike_sets(): void
    {
        $bundle = $this->bundle();
        $path = $bundle->directory.'/map_predictions.csv';
        $contents = str_replace('2024,101,1-2-3,0.02,1-2-3,0.12', '2024,101,1-2-3,0.02,1-2-4,0.12', (string) file_get_contents($path));
        file_put_contents($path, $contents);
        $bundle->sealManifest();
        try {
            $loaded = $this->loader()->load($bundle->directory);
            $race = iterator_to_array($loaded['years'][2024]->races())[0];
            $this->assertSame([1, 2, 3], $race['map_ordered_top3']);
            $this->assertSame([1, 2, 4], $race['map_top3_set']);
        } finally {
            foreach ($loaded['years'] ?? [] as $spool) {
                $spool->cleanup();
            }
            $bundle->cleanup();
        }
    }

    #[DataProvider('invalidProbabilityProvider')]
    public function test_probability_and_entrant_invariants_fail_closed(string $replacement, string $message): void
    {
        $bundle = $this->bundle();
        $path = $bundle->directory.'/probabilities.csv';
        $contents = (string) file_get_contents($path);
        $contents = preg_replace('/^2024,101,1,.*$/m', $replacement, $contents, 1);
        file_put_contents($path, $contents);
        $bundle->sealManifest();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($message);
            $this->loader()->load($bundle->directory);
        } finally {
            $bundle->cleanup();
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidProbabilityProvider(): iterable
    {
        yield 'duplicate bike' => ['2024,101,2,0.2,0.2,0.2,0.4,0.6,1,1', 'entrants were invalid'];
        yield 'invalid probability' => ['2024,101,1,1.2,0.2,0.2,0.4,0.6,1,1', 'outside [0,1]'];
        yield 'nan' => ['2024,101,1,NAN,0.2,0.2,0.4,0.6,1,1', 'was invalid'];
        yield 'position sum' => ['2024,101,1,0.1,0.2,0.2,0.3,0.5,1,1', 'sum invariant'];
        yield 'top2 mismatch' => ['2024,101,1,0.2,0.2,0.2,0.5,0.6,1,1', 'marginal probability'];
    }

    /** @dataProvider supportedEntrantCountProvider */
    #[DataProvider('supportedEntrantCountProvider')]
    public function test_five_seven_and_nine_car_probability_races_are_supported(int $entrantCount): void
    {
        $bundle = $this->bundle();
        $this->rewriteCsv($bundle, $entrantCount);
        try {
            $loaded = $this->loader()->load($bundle->directory);
            $this->assertSame($entrantCount, $loaded['years'][2024]->metadata()['entry_count']);
        } finally {
            foreach ($loaded['years'] ?? [] as $spool) {
                $spool->cleanup();
            }
            $bundle->cleanup();
        }
    }

    /** @return iterable<string,array{int}> */
    public static function supportedEntrantCountProvider(): iterable
    {
        yield 'five' => [5];
        yield 'seven' => [7];
        yield 'nine' => [9];
    }

    /** @dataProvider unsupportedEntrantCountProvider */
    #[DataProvider('unsupportedEntrantCountProvider')]
    public function test_entrant_counts_outside_five_to_nine_are_rejected(int $entrantCount): void
    {
        $bundle = $this->bundle();
        $this->rewriteCsv($bundle, $entrantCount);
        try {
            $this->expectException(RuntimeException::class);
            $this->loader()->load($bundle->directory);
        } finally {
            $bundle->cleanup();
        }
    }

    /** @return iterable<string,array{int}> */
    public static function unsupportedEntrantCountProvider(): iterable
    {
        yield 'four' => [4];
        yield 'ten' => [10];
    }

    private function loader(): Bt03e04SourceBundleLoader
    {
        $hasher = new CanonicalHasher;

        return new Bt03e04SourceBundleLoader($hasher, new Bt03e03ReproducibilityVerifier($hasher));
    }

    private function bundle(): Bt03e04SyntheticBundle
    {
        return new Bt03e04SyntheticBundle(sys_get_temp_dir().'/bt03e04-source-test-'.bin2hex(random_bytes(8)));
    }

    private function rewriteCsv(Bt03e04SyntheticBundle $bundle, int $entrantCount): void
    {
        $probabilities = fopen($bundle->directory.'/probabilities.csv', 'wb');
        $maps = fopen($bundle->directory.'/map_predictions.csv', 'wb');
        fputcsv($probabilities, [
            'year', 'race_id', 'bike_number', 'position_1_probability', 'position_2_probability',
            'position_3_probability', 'top2_probability', 'top3_probability', 'predicted_position', 'is_map_top3',
        ], escape: '');
        fputcsv($maps, [
            'year', 'race_id', 'map_ordered_top3', 'map_ordered_probability',
            'map_top3_set', 'map_top3_set_probability',
        ], escape: '');
        $p = 1 / $entrantCount;
        foreach ([2024 => 101, 2025 => 201] as $year => $raceId) {
            foreach (range(1, $entrantCount) as $offset) {
                $bike = (($offset - 1) % 9) + 1;
                fputcsv($probabilities, [
                    $year, $raceId, $bike, sprintf('%.17g', $p), sprintf('%.17g', $p), sprintf('%.17g', $p),
                    sprintf('%.17g', 2 * $p), sprintf('%.17g', 3 * $p), $offset, $offset <= 3 ? 1 : 0,
                ], escape: '');
            }
            fputcsv($maps, [$year, $raceId, '1-2-3', '0.01', '1-2-3', '0.06'], escape: '');
        }
        fclose($probabilities);
        fclose($maps);
        $bundle->mutateResult(static function (array $result) use ($entrantCount): array {
            foreach ([2024, 2025] as $year) {
                $result["outer_{$year}"]['prediction_manifest']['entry_count'] = $entrantCount;
            }

            return $result;
        });
    }
}
