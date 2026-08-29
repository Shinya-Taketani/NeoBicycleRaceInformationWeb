<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use RuntimeException;

final class Bt03e07SourceBundleLoader
{
    public function __construct(private readonly Bt03e05SourceBundleLoader $loader) {}

    /** @return array<string,mixed> */
    public function load(string $directory, string $temporaryDirectory = '/tmp'): array
    {
        $source = $this->loader->load($directory, $temporaryDirectory);
        $result = $source['source_result'] ?? null;
        if (! is_array($result)) {
            $this->cleanup($source);
            throw new RuntimeException('BT-03E-07 source result was invalid.');
        }
        $contract = $result['contract'] ?? null;
        if (($result['calculation_version'] ?? null) !== Bt03e07Contract::SOURCE_CALCULATION_VERSION
            || ! is_array($contract)
            || ($contract['contract'] ?? null) !== Bt03e07Contract::SOURCE_CONTRACT_NAME
            || ($contract['calculation_version'] ?? null) !== Bt03e07Contract::SOURCE_CALCULATION_VERSION
            || ($contract['optimizer_version'] ?? null) !== Bt03e07Contract::SOURCE_OPTIMIZER_VERSION
            || ($contract['iteration_semantics_version'] ?? null) !== Bt03e07Contract::SOURCE_ITERATION_SEMANTICS_VERSION
            || ($contract['probability_version'] ?? null) !== Bt03e07Contract::SOURCE_PROBABILITY_VERSION
            || ($contract['tie_rule_version'] ?? null) !== Bt03e07Contract::SOURCE_TIE_RULE_VERSION
            || ($contract['artifact_version'] ?? null) !== Bt03e07Contract::SOURCE_ARTIFACT_VERSION
            || ($contract['prediction_manifest_version'] ?? null) !== Bt03e07Contract::SOURCE_PREDICTION_MANIFEST_VERSION) {
            $this->cleanup($source);
            throw new RuntimeException('BT-03E-07 rejected a source outside the literal frozen E03 v2 contract.');
        }
        if (array_key_exists('outer_2026', $result)) {
            $this->cleanup($source);
            throw new RuntimeException('BT-03E-07 source bundle contained forbidden 2026 data.');
        }
        $outer = [];
        foreach (Bt03e07Contract::OUTER_YEARS as $year) {
            $value = $result["outer_{$year}"] ?? null;
            if (! is_array($value) || ! is_array($value['model']['bins'] ?? null)
                || ($value['model']['optimizer_version'] ?? null) !== Bt03e07Contract::SOURCE_OPTIMIZER_VERSION
                || ($value['model']['probability_version'] ?? null) !== Bt03e07Contract::SOURCE_PROBABILITY_VERSION
                || ($value['model']['tie_rule_version'] ?? null) !== Bt03e07Contract::SOURCE_TIE_RULE_VERSION
                || ! is_numeric($value['model']['lambda'] ?? null)
                || ! in_array((float) $value['model']['lambda'], Bt03e07Contract::LAMBDA_GRID, true)
                || ! is_array($value['prediction_manifest'] ?? null)
                || ($value['prediction_manifest']['version'] ?? null) !== Bt03e07Contract::SOURCE_PREDICTION_MANIFEST_VERSION
                || ! is_int($value['prediction_manifest']['race_count'] ?? null)
                || ! is_int($value['prediction_manifest']['entry_count'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', (string) ($value['prediction_manifest']['semantic_sha256'] ?? '')) !== 1) {
                $this->cleanup($source);
                throw new RuntimeException("BT-03E-07 source Outer {$year} model or prediction identity was invalid.");
            }
            $outer[$year] = [
                'model' => [
                    'optimizer_version' => $value['model']['optimizer_version'],
                    'probability_version' => $value['model']['probability_version'],
                    'tie_rule_version' => $value['model']['tie_rule_version'],
                    'lambda' => $value['model']['lambda'],
                    'bins' => $value['model']['bins'],
                ],
                'prediction_manifest' => $value['prediction_manifest'],
            ];
        }
        $sanitized = [
            'calculation_version' => $result['calculation_version'],
            'contract' => array_intersect_key($contract, array_flip([
                'contract', 'calculation_version', 'optimizer_version', 'iteration_semantics_version',
                'probability_version', 'tie_rule_version', 'artifact_version', 'prediction_manifest_version',
            ])),
            'source_integrity' => ['start' => $result['source_integrity']['start'] ?? null],
            'outcome_snapshot' => ['start' => $result['outcome_snapshot']['start'] ?? null],
            'outer_2024' => $outer[2024],
            'outer_2025' => $outer[2025],
        ];
        if (! is_array($sanitized['source_integrity']['start']) || ! is_array($sanitized['outcome_snapshot']['start'])) {
            $this->cleanup($source);
            throw new RuntimeException('BT-03E-07 source integrity identities were unavailable.');
        }

        return ['identity' => $source['identity'], 'source_result' => $sanitized, 'years' => $source['years']];
    }

    /** @param array<string,mixed> $source */
    private function cleanup(array $source): void
    {
        foreach (($source['years'] ?? []) as $spool) {
            if (is_object($spool) && method_exists($spool, 'cleanup')) {
                $spool->cleanup();
            }
        }
    }
}
