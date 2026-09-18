<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;

class Store
{
    public const INVENTORY = ['contract.json', 'sources.json', 'code.json', 'cohort.jsonl', 'cohort.jsonl.manifest.json',
        'history.jsonl', 'history.jsonl.manifest.json', 'history-start.json', 'thresholds.json', 'growth-input.jsonl',
        'growth-input.jsonl.manifest.json', 'growth-details.jsonl', 'growth-details.jsonl.manifest.json',
        'summary.json', 'summary.csv', 'correlations.json', 'classification.json', 'c1-diagnostics.json', 'source-end.json'];

    public function __construct(private readonly ResultStore $writer) {}

    public function publish(string $stage, string $destination, array $expected, array $summary): array
    {
        if (array_keys($expected) !== self::INVENTORY) {
            throw new RuntimeException('Incomplete growth artifact inventory.');
        }
        $this->writer->verifyGenerated($stage, $expected, $summary);
        $manifest = ['status' => 'GROWTH_ANALYSIS_LOCKED', 'contract' => Contract::plan(), 'files' => $expected,
            'published_at' => gmdate(DATE_ATOM), 'verification' => 'WRITE_TIME_SEALS_AND_STRICT_SUMMARY'];
        $seal = $this->writer->writeJson($stage, 'manifest.json', $manifest);
        $seal += $this->writer->writeJson($stage, 'LOCKED.json', $seal['manifest.json']);
        $this->verify($stage);
        $this->writer->verifyGenerated($stage, $seal);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot publish without overwriting.');
        }

        return $manifest;
    }

    public function verify(string $path): array
    {
        if (realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('Unsafe growth bundle.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/LOCKED.json'));
        $manifest = Files::json($path.'/manifest.json');
        if (($manifest['status'] ?? null) !== 'GROWTH_ANALYSIS_LOCKED' || array_keys($manifest['files'] ?? []) !== self::INVENTORY) {
            throw new RuntimeException('Invalid growth inventory.');
        }
        Files::same(Contract::plan(), $manifest['contract'], 'growth contract');
        Files::same(Contract::plan(), Files::json($path.'/contract.json'), 'saved growth contract');
        $this->writer->verifyGenerated($path, $manifest['files']);

        return $manifest;
    }
}
