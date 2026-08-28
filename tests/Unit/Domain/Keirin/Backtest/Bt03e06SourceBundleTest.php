<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e03ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e05SourceBundleLoader;
use App\Domain\Keirin\Backtest\Services\Bt03e06SourceBundleLoader;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Bt03e05SyntheticBundle;

class Bt03e06SourceBundleTest extends TestCase
{
    public function test_verified_e03_v2_is_accepted_with_literal_tie_contract(): void
    {
        $bundle = $this->bundle();
        try {
            $loaded = $this->loader()->load($bundle->directory);
            $this->assertSame([2024, 2025], array_keys($loaded['years']));
            $this->assertSame('BT03E03-ORDERED-TOP3-TIE-v1', $loaded['source_result']['outer_2024']['model']['tie_rule_version']);
        } finally {
            foreach ($loaded['years'] ?? [] as $spool) {
                $spool->cleanup();
            }
            $bundle->cleanup();
        }
    }

    public function test_future_tie_version_is_rejected(): void
    {
        $bundle = $this->bundle();
        $bundle->mutateResult(static function (array $result): array {
            $result['contract']['tie_rule_version'] = 'BT03E03-ORDERED-TOP3-TIE-v2';
            foreach ([2024, 2025] as $year) {
                $result["outer_{$year}"]['model']['tie_rule_version'] = 'BT03E03-ORDERED-TOP3-TIE-v2';
            }

            return $result;
        });
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('frozen E03 v2 contract');
            $this->loader()->load($bundle->directory);
        } finally {
            $bundle->cleanup();
        }
    }

    public function test_2026_source_csv_is_rejected(): void
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

    private function bundle(): Bt03e05SyntheticBundle
    {
        return new Bt03e05SyntheticBundle(sys_get_temp_dir().'/bt03e06-source-'.bin2hex(random_bytes(8)));
    }

    private function loader(): Bt03e06SourceBundleLoader
    {
        $hasher = new CanonicalHasher;

        return new Bt03e06SourceBundleLoader(new Bt03e05SourceBundleLoader(
            $hasher,
            new Bt03e03ReproducibilityVerifier($hasher),
        ));
    }
}
