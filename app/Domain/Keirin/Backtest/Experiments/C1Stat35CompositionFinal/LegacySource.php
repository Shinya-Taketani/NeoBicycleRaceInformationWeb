<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final readonly class LegacySource
{
    public function __construct(
        public string $source = Repackage::SOURCE,
        public array $manifest = Repackage::MANIFEST,
        private string $c1Sha = Contract::C1_SHA,
        private string $c2Sha = 'd1dbb706071a7dc25d4ea8fa0525ac685a8b68d6d09f3a43a980a70dc1334ea2',
    ) {}

    public function verifyEvidence(array $evidence): void
    {
        if (($evidence['source_root'] ?? null) !== $this->source || ($evidence['root_manifest'] ?? null) !== $this->manifest
            || ($evidence['legacy_contract'] ?? null) !== Contract::VERSION || ($evidence['historical_semantic_file_count'] ?? null) !== 37
            || ($evidence['historical_reproduction_identical'] ?? null) !== true || ($evidence['retraining_count'] ?? null) !== 0
            || ($evidence['parent_files']['c1/model.json']['sha256'] ?? null) !== $this->c1Sha
            || ($evidence['parent_files']['c2/model.json']['sha256'] ?? null) !== $this->c2Sha) {
            throw new RuntimeException('Portable export has no pinned legacy success provenance.');
        }
        Files::same(Contract::code(), $evidence['runtime_code'] ?? [], 'portable publication runtime');
    }
}
