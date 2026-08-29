<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e07SourceBundleLoader;
use Tests\Support\Bt03e05SyntheticBundle;
use Tests\TestCase;

final class Bt03e07SourceBundleTest extends TestCase
{
    public function test_loader_exposes_only_frozen_source_identity_and_strips_source_performance_evidence(): void
    {
        $fixture = new Bt03e05SyntheticBundle(sys_get_temp_dir().'/bt03e07-source-'.bin2hex(random_bytes(8)));
        $fixture->mutateResult(static function (array $result): array {
            foreach ([2024, 2025] as $year) {
                $result["outer_{$year}"]['model']['lambda'] = 0.1;
                $result["outer_{$year}"]['model']['bins'] = ['STAT-07' => [['index' => 1]]];
                $result["outer_{$year}"]['metrics'] = ['forbidden' => 0.99];
            }
            $result['paired_bootstrap_ci'] = ['forbidden' => true];
            $result['acceptance_gate_input'] = ['forbidden' => true];

            return $result;
        });
        $loaded = [];
        try {
            $loaded = app(Bt03e07SourceBundleLoader::class)->load($fixture->directory);
            $source = $loaded['source_result'];
            $this->assertArrayNotHasKey('paired_bootstrap_ci', $source);
            $this->assertArrayNotHasKey('acceptance_gate_input', $source);
            $this->assertArrayNotHasKey('metrics', $source['outer_2024']);
            $this->assertSame(['STAT-07' => [['index' => 1]]], $source['outer_2024']['model']['bins']);
            $this->assertArrayHasKey('source_integrity', $source);
            $this->assertArrayHasKey('outcome_snapshot', $source);
        } finally {
            foreach (($loaded['years'] ?? []) as $spool) {
                $spool->cleanup();
            }
            $fixture->cleanup();
        }
    }
}
