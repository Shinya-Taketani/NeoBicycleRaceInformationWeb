<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e04DecisionDecoder;
use App\Domain\Keirin\Backtest\Services\Bt03e03ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e04SourceBundleLoader;
use App\Domain\Keirin\Backtest\Support\Bt03e04DecoderManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e04RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use Tests\Support\Bt03e04SyntheticBundle;

class Bt03e04BoundedMemoryTest extends TestCase
{
    public function test_two_thousand_nine_car_source_races_are_stream_parsed_under_the_process_limit(): void
    {
        $bundle = new Bt03e04SyntheticBundle(
            sys_get_temp_dir().'/bt03e04-bounded-source-'.bin2hex(random_bytes(8)),
            1000,
            9,
        );
        $hasher = new CanonicalHasher;
        $loader = new Bt03e04SourceBundleLoader($hasher, new Bt03e03ReproducibilityVerifier($hasher));
        try {
            $loaded = $loader->load($bundle->directory);

            $this->assertSame(1000, $loaded['years'][2024]->metadata()['race_count']);
            $this->assertSame(1000, $loaded['years'][2025]->metadata()['race_count']);
            $this->assertSame(9000, $loaded['years'][2024]->metadata()['entry_count']);
            $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
        } finally {
            foreach ($loaded['years'] ?? [] as $spool) {
                $spool->cleanup();
            }
            $bundle->cleanup();
        }
    }

    public function test_two_thousand_nine_car_decisions_are_spooled_under_the_process_limit(): void
    {
        $spool = new Bt03e04RaceSpool('DECODER', sys_get_temp_dir().'/bt03e04-bounded-'.bin2hex(random_bytes(8)).'.jsonl');
        $manifest = new Bt03e04DecoderManifestAccumulator(new CanonicalHasher);
        $decoder = new Bt03e04DecisionDecoder;
        try {
            foreach (range(1, 2000) as $raceId) {
                $decision = $decoder->decode($this->source($raceId));
                $manifest->append($decision);
                $spool->append($decision);
            }
            $spool->seal();

            $this->assertSame(2000, $spool->metadata()['race_count']);
            $this->assertSame(2000, $manifest->seal()['race_count']);
            $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
        } finally {
            $spool->cleanup();
        }
    }

    /** @return array<string,mixed> */
    private function source(int $raceId): array
    {
        $entries = [];
        foreach (range(1, 9) as $bike) {
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => 1 / 9,
                'position_2_probability' => 1 / 9,
                'position_3_probability' => 1 / 9,
                'top2_probability' => 2 / 9,
                'top3_probability' => 3 / 9,
            ];
        }

        return [
            'year' => 2024,
            'race_id' => $raceId,
            'entries' => $entries,
            'map_ordered_top3' => [1, 2, 3],
            'map_ordered_probability' => 1 / 504,
            'map_top3_set' => [1, 2, 3],
            'map_top3_set_probability' => 1 / 84,
        ];
    }
}
