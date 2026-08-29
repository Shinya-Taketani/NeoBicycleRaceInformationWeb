<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Services\Bt03e06ArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e06ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Support\Bt03e06DecoderManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class Bt03e06IntegrityTest extends TestCase
{
    public function test_contract_literals_do_not_dynamically_follow_e03_e04_or_e05_versions(): void
    {
        $plan = Bt03e06Contract::plan();
        $this->assertSame('BT03E06-WINNER-CONDITIONED-DECODER-v1', $plan['calculation_version']);
        $this->assertSame('BT03E06-WINNER-CONDITIONED-SEQUENTIAL-v1', $plan['decoder_version']);
        $this->assertSame('BT03E05-DECODER-TIE-v1', $plan['primary_tie_rule_version']);
        $this->assertSame('BT03E04-DECODER-TIE-v1', $plan['supporting_tie_rule_version']);
        $this->assertSame(2000, $plan['bootstrap']['iterations']);
        $this->assertSame(20260812, $plan['bootstrap']['seed']);
        $this->assertSame('FORBIDDEN', $plan['model_fitting']);
        $this->assertSame('FORBIDDEN', $plan['bin_generation']);
        $this->assertSame('FORBIDDEN', $plan['candidate_search']);
        $this->assertSame('FORBIDDEN', $plan['2026_access']);

        $path = (new ReflectionClass(Bt03e06Contract::class))->getFileName();
        $source = is_string($path) ? file_get_contents($path) : false;
        $this->assertIsString($source);
        $this->assertStringNotContainsString('Bt03e03Contract::', $source);
        $this->assertStringNotContainsString('Bt03e04Contract::', $source);
        $this->assertStringNotContainsString('Bt03e05Contract::', $source);
    }

    public function test_e06_metric_gate_and_bootstrap_adapters_are_exactly_e05(): void
    {
        $e05Metrics = new Bt03e05MetricEvaluator;
        $e06Metrics = new Bt03e06MetricEvaluator($e05Metrics);
        $comparison = $this->comparison();
        $e05Summary = $e05Metrics->emptySummary();
        $e06Summary = $e06Metrics->emptySummary();
        $e05Metrics->add($e05Summary, $comparison);
        $e06Metrics->add($e06Summary, $comparison);
        $this->assertSame($e05Metrics->finish($e05Summary), $e06Metrics->finish($e06Summary));

        $spools = [2024 => $this->metricSpool(2024, $comparison), 2025 => $this->metricSpool(2025, $comparison)];
        try {
            $e05Bootstrap = new Bt03e05PairedBootstrap(new Type7Quantile);
            $e06Bootstrap = new Bt03e06PairedBootstrap($e05Bootstrap);
            $e05Input = array_map(static fn (Bt03e06MetricContributionSpool $spool) => $spool->e05Spool(), $spools);
            $this->assertSame($e05Bootstrap->evaluate($e05Input, 25), $e06Bootstrap->evaluate($spools, 25));
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
        }

        $outer = [2024 => $this->gateYear(), 2025 => $this->gateYear()];
        $intervals = array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, ['ci_lower' => 0.01, 'ci_upper' => 0.02]);
        $e05Gate = new Bt03e05AcceptanceGate;
        $e06Gate = new Bt03e06AcceptanceGate($e05Gate);
        $this->assertSame($e05Gate->evaluate($outer, $intervals, true), $e06Gate->evaluate($outer, $intervals, true));
    }

    public function test_artifact_contains_no_official_outcome_fields(): void
    {
        $directory = sys_get_temp_dir().'/bt03e06-artifact-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $spools = [2024 => $this->decoderSpool(2024), 2025 => $this->decoderSpool(2025)];
        try {
            $summary = $this->summary();
            foreach ($spools as $year => $spool) {
                $identity = ['year' => $year, 'source_prediction_manifest_sha256' => str_repeat((string) ($year - 2024), 64)];
                $manifest = new Bt03e06DecoderManifestAccumulator($year, $identity, new CanonicalHasher);
                foreach ($spool->races() as $decision) {
                    $manifest->append($decision);
                }
                $summary['decoder_manifests'][$year] = $manifest->seal();
            }
            $summary['reproducibility_hash'] = (new Bt03e06ReproducibilityVerifier(new CanonicalHasher))->hash($summary);
            $paths = (new Bt03e06ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher))->write($directory, $summary, $spools);
            $handle = fopen($paths['decoder_predictions_csv'], 'rb');
            $header = is_resource($handle) ? fgetcsv($handle, escape: '') : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->assertIsArray($header);
            foreach (['rank', 'status', 'winner_label', 'actual_top3', 'payout', 'result'] as $forbidden) {
                $this->assertNotContains($forbidden, $header);
            }
            $this->assertContains('reconstruction_verified', $header);
            $this->assertFileExists($paths['manifest_json']);
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            $this->remove($directory);
        }
    }

    /** @return array<string,mixed> */
    private function comparison(): array
    {
        $values = [];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
            $values['candidate'][$metric] = ['numerator' => 1.0, 'denominator' => 1.0];
            $values['baseline'][$metric] = ['numerator' => 0.0, 'denominator' => 1.0];
        }
        $values['ordered_eligible'] = 1;
        $values['ties'] = [
            'primary_score_tied_races' => 0,
            'primary_score_tied_combinations' => 0,
            'technical_tiebreak_races' => 0,
            'baseline_exact_score_tied_races' => 0,
            'baseline_exact_score_tied_entries' => 0,
        ];
        $values['diagnostics'] = [
            'PRIMARY_WINNER_HIT_AT_1' => 1.0,
            'PRIMARY_EXACT_ORDERED_TOP3_RATE' => 1.0,
            'MAP_ORDERED_EXACT_ORDERED_TOP3_RATE' => 1.0,
            'MAP_TOP3_SET_RATE' => 1.0,
            'winner_eligible' => 1.0,
            'ordered_eligible' => 1.0,
        ];

        return $values;
    }

    private function metricSpool(int $year, array $comparison): Bt03e06MetricContributionSpool
    {
        $spool = new Bt03e06MetricContributionSpool(sys_get_temp_dir()."/bt03e06-metric-{$year}-".bin2hex(random_bytes(8)).'.bin');
        $spool->append($comparison);
        $spool->seal();

        return $spool;
    }

    /** @return array<string,mixed> */
    private function gateYear(): array
    {
        return [
            'delta' => array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, 0.01),
            'tie_diagnostics' => [
                'primary_score_tied_races' => 0,
                'baseline_exact_score_tied_races' => 0,
                'technical_tiebreak_races' => 0,
            ],
            'race_count' => 1000,
        ];
    }

    private function decoderSpool(int $year): Bt03e06RaceSpool
    {
        $spool = new Bt03e06RaceSpool('DECODER', sys_get_temp_dir()."/bt03e06-decision-{$year}-".bin2hex(random_bytes(8)).'.jsonl');
        $race = $this->predictionRace($year);
        $spool->append((new Bt03e06WinnerConditionedDecoder(new Bt03e03ProbabilityScorer, new CanonicalHasher))->decode($race));
        $spool->seal();

        return $spool;
    }

    /** @return array<string,mixed> */
    private function predictionRace(int $year): array
    {
        $entries = [];
        foreach (range(1, 5) as $offset => $bike) {
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => [0.4, 0.25, 0.15, 0.12, 0.08][$offset],
                'position_2_probability' => [0.1, 0.3, 0.25, 0.2, 0.15][$offset],
                'position_3_probability' => [0.1, 0.15, 0.3, 0.25, 0.2][$offset],
                'top2_probability' => [0.5, 0.55, 0.4, 0.32, 0.23][$offset],
                'top3_probability' => [0.6, 0.7, 0.7, 0.57, 0.43][$offset],
                'utilities' => ['POSITION_1' => 0.0, 'POSITION_2' => $offset / 3, 'POSITION_3' => -$offset / 4],
            ];
        }

        return [
            'year' => $year,
            'race_id' => $year,
            'entries' => $entries,
            'map_ordered_top3' => [1, 2, 3],
            'map_ordered_probability' => 0.1,
            'map_top3_set' => [1, 2, 3],
            'map_top3_set_probability' => 0.2,
        ];
    }

    /** @return array<string,mixed> */
    private function summary(): array
    {
        $metrics = ['metrics' => $this->gateYear()];

        return [
            'calculation_version' => Bt03e06Contract::CALCULATION_VERSION,
            'contract' => Bt03e06Contract::plan(),
            'source_bundle_identity' => ['source_reproducibility_hash' => str_repeat('a', 64)],
            'outer_model_canonical_hashes' => [2024 => str_repeat('b', 64), 2025 => str_repeat('c', 64)],
            'feature_source_integrity' => ['unchanged' => true],
            'outcome_snapshot_identity' => ['unchanged' => true],
            'reconstruction_manifests' => [2024 => ['semantic_sha256' => str_repeat('d', 64)], 2025 => ['semantic_sha256' => str_repeat('e', 64)]],
            'decoder_manifests' => [],
            'outer_2024' => $metrics,
            'outer_2025' => $metrics,
            'paired_bootstrap_ci' => array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, ['ci_lower' => 0.0, 'ci_upper' => 0.0]),
            'acceptance_gate_input' => ['synthetic' => true],
        ];
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
