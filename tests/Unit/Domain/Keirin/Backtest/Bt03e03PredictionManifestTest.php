<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e03ArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e03ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e03PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03e03PredictionManifestTest extends TestCase
{
    public function test_same_prediction_spool_has_the_same_semantic_manifest(): void
    {
        $spool = $this->spool(2024, 2);
        try {
            $first = $this->manifest($spool);
            $second = $this->manifest($spool);

            $this->assertSame($first, $second);
            $this->assertSame(Bt03e03Contract::PREDICTION_MANIFEST_VERSION, $first['version']);
            $this->assertSame(2, $first['race_count']);
            $this->assertSame(10, $first['entry_count']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first['semantic_sha256']);
        } finally {
            $spool->cleanup();
        }
    }

    public function test_existing_spool_order_is_part_of_the_semantic_identity_without_requiring_race_id_order(): void
    {
        $first = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
        $first->append($this->prediction(2024, 20));
        $first->append($this->prediction(2024, 10));

        $second = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
        $second->append($this->prediction(2024, 10));
        $second->append($this->prediction(2024, 20));

        $this->assertNotSame($first->seal()['semantic_sha256'], $second->seal()['semantic_sha256']);
    }

    public function test_every_probability_and_prediction_identity_field_changes_the_hash(): void
    {
        $base = $this->prediction(2024, 1);
        $baseHash = $this->manifestForRace($base)['semantic_sha256'];
        foreach ([
            'position_1_probability',
            'position_2_probability',
            'position_3_probability',
            'top2_probability',
            'top3_probability',
        ] as $field) {
            $changed = $base;
            $changed['entries'][0][$field] += 0.001;
            $this->assertNotSame($baseHash, $this->manifestForRace($changed)['semantic_sha256'], $field);
        }

        $changed = $base;
        $changed['entries'][0]['predicted_position'] = 2;
        $this->assertNotSame($baseHash, $this->manifestForRace($changed)['semantic_sha256']);

        $changed = $base;
        $changed['entries'][0]['is_map_top3'] = false;
        $this->assertNotSame($baseHash, $this->manifestForRace($changed)['semantic_sha256']);
    }

    public function test_every_map_field_changes_the_hash(): void
    {
        $base = $this->prediction(2024, 1);
        $baseHash = $this->manifestForRace($base)['semantic_sha256'];
        foreach ([
            'map_ordered_top3' => [1, 3, 2],
            'map_ordered_probability' => 0.021,
            'map_top3_set' => [1, 2, 4],
            'map_top3_set_probability' => 0.121,
            'map_tie_diagnostics' => ['technical_tiebreak_used' => true],
        ] as $field => $value) {
            $changed = $base;
            $changed[$field] = $value;
            foreach ($changed['entries'] as &$entry) {
                $entry[$field] = $value;
            }
            unset($entry);
            $this->assertNotSame($baseHash, $this->manifestForRace($changed)['semantic_sha256'], $field);
        }
    }

    public function test_outcome_rank_and_status_do_not_change_prediction_identity(): void
    {
        $base = $this->prediction(2024, 1);
        $changed = $base;
        foreach ($changed['entries'] as &$entry) {
            $entry['rank'] = null;
            $entry['status'] = 'DISQUALIFIED';
        }
        unset($entry);

        $this->assertSame($this->manifestForRace($base), $this->manifestForRace($changed));
    }

    public function test_prediction_manifest_changes_the_reproducibility_hash(): void
    {
        $verifier = new Bt03e03ReproducibilityVerifier(new CanonicalHasher);
        $base = $this->summary([
            2024 => $this->manifestForRace($this->prediction(2024, 1)),
            2025 => $this->manifestForRace($this->prediction(2025, 2)),
        ]);
        $changed = $base;
        $changed['outer_2024']['prediction_manifest']['semantic_sha256'] = str_repeat('f', 64);

        $this->assertNotSame($verifier->hash($base), $verifier->hash($changed));
    }

    public function test_artifact_writer_rejects_a_prediction_manifest_mismatch_before_publication(): void
    {
        $directory = sys_get_temp_dir().'/bt03e03-manifest-artifact-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $spools = [2024 => $this->spool(2024, 1), 2025 => $this->spool(2025, 1)];
        try {
            $manifests = array_map(fn (Bt03e02RaceSpool $spool): array => $this->manifest($spool), $spools);
            $summary = $this->summary($manifests);
            $summary['outer_2024']['prediction_manifest']['semantic_sha256'] = str_repeat('f', 64);
            $summary['reproducibility_hash'] = (new Bt03e03ReproducibilityVerifier(new CanonicalHasher))->hash($summary);

            try {
                (new Bt03e03ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher))->write(
                    $directory,
                    $summary,
                    $spools,
                );
                $this->fail('A mismatched prediction stream must not be published.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('prediction semantic manifest mismatched', $exception->getMessage());
            }
            $this->assertSame([], array_values(array_diff(scandir($directory) ?: [], ['.', '..'])));
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            rmdir($directory);
        }
    }

    public function test_two_thousand_race_spool_manifest_replay_is_bounded(): void
    {
        $path = sys_get_temp_dir().'/bt03e03-manifest-memory-'.bin2hex(random_bytes(8)).'.jsonl';
        $spool = new Bt03e02RaceSpool('PREDICTION', $path);
        $startPeak = memory_get_peak_usage(true);
        try {
            foreach (range(1, 2000) as $raceId) {
                $spool->append($this->prediction(2024, $raceId));
            }
            $spool->seal();
            $manifest = $this->manifest($spool);

            $this->assertSame(2000, $manifest['race_count']);
            $this->assertSame(10000, $manifest['entry_count']);
            $this->assertLessThan(24 * 1024 * 1024, memory_get_peak_usage(true) - $startPeak);
        } finally {
            $spool->cleanup();
        }
    }

    private function spool(int $year, int $raceCount): Bt03e02RaceSpool
    {
        $spool = new Bt03e02RaceSpool(
            'PREDICTION',
            sys_get_temp_dir().'/bt03e03-manifest-'.bin2hex(random_bytes(8)).'.jsonl',
        );
        foreach (range(1, $raceCount) as $offset) {
            $spool->append($this->prediction($year, ($year - 2024) * 10000 + $offset));
        }
        $spool->seal();

        return $spool;
    }

    /** @return array{version:string,race_count:int,entry_count:int,semantic_sha256:string} */
    private function manifest(Bt03e02RaceSpool $spool): array
    {
        $accumulator = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
        foreach ($spool->races() as $race) {
            $accumulator->append($race);
        }

        return $accumulator->seal();
    }

    /** @param array<string,mixed> $race @return array{version:string,race_count:int,entry_count:int,semantic_sha256:string} */
    private function manifestForRace(array $race): array
    {
        $accumulator = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
        $accumulator->append($race);

        return $accumulator->seal();
    }

    /** @return array<string,mixed> */
    private function prediction(int $year, int $raceId): array
    {
        $mapOrdered = [1, 2, 3];
        $mapSet = [1, 2, 3];
        $diagnostics = ['technical_tiebreak_used' => false];
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => 0.2,
                'position_2_probability' => 0.2,
                'position_3_probability' => 0.2,
                'top2_probability' => 0.4,
                'top3_probability' => 0.6,
                'predicted_position' => $bike,
                'is_map_top3' => $bike <= 3,
                'map_ordered_top3' => $mapOrdered,
                'map_ordered_probability' => 0.02,
                'map_top3_set' => $mapSet,
                'map_top3_set_probability' => 0.12,
                'map_tie_diagnostics' => $diagnostics,
                'rank' => $bike,
                'status' => 'FINISHED',
            ];
        }

        return [
            'year' => $year,
            'race_id' => $raceId,
            'entries' => $entries,
            'map_ordered_top3' => $mapOrdered,
            'map_ordered_probability' => 0.02,
            'map_top3_set' => $mapSet,
            'map_top3_set_probability' => 0.12,
            'map_tie_diagnostics' => $diagnostics,
        ];
    }

    /** @param array<int,array<string,mixed>> $manifests @return array<string,mixed> */
    private function summary(array $manifests): array
    {
        $outer = static fn (array $manifest): array => [
            'lambda_selection' => [],
            'model' => [],
            'refit_path' => [],
            'metrics' => [],
            'probability_metrics' => [],
            'calibration' => [],
            'map_diagnostics' => [],
            'prediction_manifest' => $manifest,
        ];

        return [
            'calculation_version' => Bt03e03Contract::CALCULATION_VERSION,
            'contract' => Bt03e03Contract::plan(),
            'source_integrity' => [],
            'outcome_snapshot' => [],
            'outer_2024' => $outer($manifests[2024]),
            'outer_2025' => $outer($manifests[2025]),
            'paired_bootstrap_ci' => [],
            'acceptance_gate_input' => [],
        ];
    }
}
