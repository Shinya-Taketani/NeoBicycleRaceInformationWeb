<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use RuntimeException;

final class Bt03e08SourceBundleLoader
{
    public function __construct(private readonly Bt03e06SourceBundleLoader $loader) {}

    /** @return array<string,mixed> */
    public function load(string $directory, string $temporaryDirectory = '/tmp'): array
    {
        $source = $this->loader->load($directory, $temporaryDirectory);
        $result = $source['source_result'] ?? null;
        $contract = is_array($result) ? ($result['contract'] ?? null) : null;
        if (! is_array($result) || ! is_array($contract)
            || ($result['calculation_version'] ?? null) !== Bt03e08Contract::SOURCE_CALCULATION_VERSION
            || ($contract['contract'] ?? null) !== Bt03e08Contract::SOURCE_CONTRACT_NAME
            || ($contract['optimizer_version'] ?? null) !== Bt03e08Contract::SOURCE_OPTIMIZER_VERSION
            || ($contract['iteration_semantics_version'] ?? null) !== Bt03e08Contract::SOURCE_ITERATION_SEMANTICS_VERSION
            || ($contract['probability_version'] ?? null) !== Bt03e08Contract::SOURCE_PROBABILITY_VERSION
            || ($contract['tie_rule_version'] ?? null) !== Bt03e08Contract::SOURCE_TIE_RULE_VERSION
            || ($contract['artifact_version'] ?? null) !== Bt03e08Contract::SOURCE_ARTIFACT_VERSION
            || ($contract['prediction_manifest_version'] ?? null) !== Bt03e08Contract::SOURCE_PREDICTION_MANIFEST_VERSION
            || array_key_exists('outer_2026', $result)) {
            foreach (($source['years'] ?? []) as $spool) {
                $spool->cleanup();
            }
            throw new RuntimeException('BT-03E-08 rejected a source outside the literal frozen E03 v2 contract.');
        }

        return $source;
    }
}
