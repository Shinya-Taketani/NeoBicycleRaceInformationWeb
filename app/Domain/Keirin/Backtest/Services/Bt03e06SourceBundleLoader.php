<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use RuntimeException;

final class Bt03e06SourceBundleLoader
{
    public function __construct(private readonly Bt03e05SourceBundleLoader $loader) {}

    /** @return array<string,mixed> */
    public function load(string $directory, string $temporaryDirectory = '/tmp'): array
    {
        $source = $this->loader->load($directory, $temporaryDirectory);
        $result = $source['source_result'] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('BT-03E-06 source result was invalid.');
        }
        $contract = $result['contract'] ?? null;
        if (($result['calculation_version'] ?? null) !== Bt03e06Contract::SOURCE_CALCULATION_VERSION
            || ! is_array($contract)
            || ($contract['contract'] ?? null) !== Bt03e06Contract::SOURCE_CONTRACT_NAME
            || ($contract['calculation_version'] ?? null) !== Bt03e06Contract::SOURCE_CALCULATION_VERSION
            || ($contract['optimizer_version'] ?? null) !== Bt03e06Contract::SOURCE_OPTIMIZER_VERSION
            || ($contract['iteration_semantics_version'] ?? null) !== Bt03e06Contract::SOURCE_ITERATION_SEMANTICS_VERSION
            || ($contract['probability_version'] ?? null) !== Bt03e06Contract::SOURCE_PROBABILITY_VERSION
            || ($contract['tie_rule_version'] ?? null) !== Bt03e06Contract::SOURCE_TIE_RULE_VERSION
            || ($contract['artifact_version'] ?? null) !== Bt03e06Contract::SOURCE_ARTIFACT_VERSION
            || ($contract['prediction_manifest_version'] ?? null) !== Bt03e06Contract::SOURCE_PREDICTION_MANIFEST_VERSION) {
            $this->cleanup($source);
            throw new RuntimeException('BT-03E-06 rejected a source model outside the frozen E03 v2 contract.');
        }
        if (array_key_exists('outer_2026', $result)) {
            $this->cleanup($source);
            throw new RuntimeException('BT-03E-06 source bundle contained forbidden 2026 data.');
        }
        foreach (Bt03e06Contract::DEVELOPMENT_YEARS as $year) {
            $outer = $result["outer_{$year}"] ?? null;
            if (! is_array($outer)
                || ($outer['model']['optimizer_version'] ?? null) !== Bt03e06Contract::SOURCE_OPTIMIZER_VERSION
                || ($outer['model']['probability_version'] ?? null) !== Bt03e06Contract::SOURCE_PROBABILITY_VERSION
                || ($outer['model']['tie_rule_version'] ?? null) !== Bt03e06Contract::SOURCE_TIE_RULE_VERSION
                || ($outer['prediction_manifest']['version'] ?? null) !== Bt03e06Contract::SOURCE_PREDICTION_MANIFEST_VERSION) {
                $this->cleanup($source);
                throw new RuntimeException("BT-03E-06 source Outer {$year} contract was invalid.");
            }
        }

        return $source;
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
