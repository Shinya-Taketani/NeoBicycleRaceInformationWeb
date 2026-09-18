<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;

class Store
{
    public const INVENTORY = ['contract.json', 'sources.json', 'code.json', 'v1-source-verification.json',
        'thresholds-v2.json', 'growth-details-v2.jsonl', 'growth-details-v2.jsonl.manifest.json', 'raw-verification.json',
        'point-transition.json', 'summary-v2.json', 'summary-v2.csv', 'correlations-v2.json', 'classification-v2.json',
        'c1-diagnostics-v2.json', 'comparison-v1-v2.json', 'source-end.json'];

    public function __construct(private readonly ResultStore $writer) {}

    public function publish(string $stage, string $destination, array $expected, array $summary): array
    {
        if (array_keys($expected) !== self::INVENTORY) {
            throw new RuntimeException('Incomplete v2 inventory.');
        }
        $this->writer->verifyGenerated($stage, $expected);
        Files::same($summary, Files::json($stage.'/summary-v2.json'), 'v2 summary');
        $manifest = ['status' => 'GROWTH_V2_LOCKED', 'contract' => Contract::plan(), 'files' => $expected,
            'published_at' => gmdate(DATE_ATOM), 'verification' => 'WRITE_TIME_SEALS_AND_RAW_EQUALITY'];
        $seal = $this->writer->writeJson($stage, 'manifest.json', $manifest);
        $seal += $this->writer->writeJson($stage, 'LOCKED.json', $seal['manifest.json']);
        $this->verify($stage);
        $this->writer->verifyGenerated($stage, $seal);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot publish v2 without overwrite.');
        }

        return $manifest;
    }

    public function verify(string $path): array
    {
        if (realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('Unsafe v2 bundle.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/LOCKED.json'));
        $manifest = Files::json($path.'/manifest.json');
        if (($manifest['status'] ?? null) !== 'GROWTH_V2_LOCKED' || array_keys($manifest['files'] ?? []) !== self::INVENTORY) {
            throw new RuntimeException('Invalid v2 inventory/status.');
        }
        Files::same(Contract::plan(), $manifest['contract'], 'v2 manifest contract');
        $this->writer->verifyGenerated($path, $manifest['files']);
        Files::same(Contract::plan(), Files::json($path.'/contract.json'), 'saved v2 contract');

        return $manifest;
    }
}
