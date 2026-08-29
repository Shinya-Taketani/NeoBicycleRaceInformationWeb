<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07P1FrozenDecisionDecoder;
use App\Domain\Keirin\Backtest\DTO\Bt03e07FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e07ArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e07PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class Bt03e07ArtifactIsolationTest extends TestCase
{
    private const FORBIDDEN_OUTCOME_KEYS = [
        'rank', 'status', 'label', 'actual', 'winner_label', 'actual_top2', 'actual_top3', 'payout', 'result',
    ];

    public function test_actual_writer_publishes_only_outcome_free_predictions_and_revalidates_manifests(): void
    {
        $directory = sys_get_temp_dir().'/bt03e07-artifact-isolation-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $spools = [];
        try {
            $summary = ['reproducibility_hash' => str_repeat('a', 64), 'prediction_manifests' => []];
            foreach ([2024, 2025] as $year) {
                $spool = new Bt03e06RaceSpool(
                    'DECODER',
                    sys_get_temp_dir()."/bt03e07-artifact-prediction-{$year}-".bin2hex(random_bytes(8)).'.jsonl',
                );
                $decision = $this->decision($year);
                $spool->append($decision);
                $spool->seal();
                $spools[$year] = $spool;

                $sourceIdentity = ['year' => $year, 'semantic_sha256' => str_repeat((string) ($year - 2024), 64)];
                $manifest = new Bt03e07PredictionManifestAccumulator($year, $sourceIdentity, new CanonicalHasher);
                $manifest->append($decision);
                $summary['prediction_manifests'][$year] = $manifest->seal();
            }

            $paths = (new Bt03e07ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher))->write(
                $directory,
                $summary,
                $spools,
            );

            $handle = fopen($paths['predictions_csv'], 'rb');
            $this->assertIsResource($handle);
            $header = fgetcsv($handle, escape: '');
            $this->assertIsArray($header);
            foreach (self::FORBIDDEN_OUTCOME_KEYS as $forbidden) {
                $this->assertNotContains($forbidden, $header);
            }
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $this->assertCount(count($header), $row);
            }
            fclose($handle);

            $published = json_decode((string) file_get_contents($paths['result_json']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($published['prediction_manifests'] ?? null);
            $this->assertNoOutcomeKey($published['prediction_manifests']);
            $this->assertFileExists($paths['manifest_json']);
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            $this->remove($directory);
        }
    }

    /** @return array<string,mixed> */
    private function decision(int $year): array
    {
        $binnedEntries = $sourceEntries = [];
        foreach (range(1, 5) as $bike) {
            $binnedEntries[] = [
                'id' => $year * 10 + $bike,
                'bike' => $bike,
                'anchor' => 0.0,
                'bins' => array_fill(0, 12, null),
            ];
            $p1 = $bike === 1 ? 0.4 : 0.15;
            $sourceEntries[] = [
                'bike' => $bike,
                'position_1_probability' => $p1,
                'position_2_probability' => 0.2,
                'position_3_probability' => 0.2,
                'top2_probability' => $p1 + 0.2,
                'top3_probability' => $p1 + 0.4,
            ];
        }
        $fit = new Bt03e07FitResultDto(0.0, [
            'POSITION_2' => array_fill(0, 12, 0.0),
            'POSITION_3' => array_fill(0, 12, 0.0),
        ], [], [], [], [], []);
        $direct = (new Bt03e07DirectPositionScorer(new CanonicalHasher))->predict([
            'year' => $year,
            'race_id' => $year,
            'entries' => $binnedEntries,
        ], $fit);

        return (new Bt03e07P1FrozenDecisionDecoder)->decode([
            'year' => $year,
            'race_id' => $year,
            'entries' => $sourceEntries,
            'map_ordered_top3' => [1, 2, 3],
            'map_ordered_probability' => 0.1,
            'map_top3_set' => [1, 2, 3],
            'map_top3_set_probability' => 0.2,
        ], $direct);
    }

    /** @param array<mixed> $value */
    private function assertNoOutcomeKey(array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                foreach (self::FORBIDDEN_OUTCOME_KEYS as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, strtolower($key));
                }
            }
            if (is_array($item)) {
                $this->assertNoOutcomeKey($item);
            }
        }
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            $path = $directory.'/'.$file;
            if (is_dir($path)) {
                $this->remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
