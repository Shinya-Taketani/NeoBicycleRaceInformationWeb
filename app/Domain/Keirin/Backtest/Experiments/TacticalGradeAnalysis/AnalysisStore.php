<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;

class AnalysisStore
{
    public const INVENTORY = ['contract.json', 'sources.json', 'code.json', 'matched.jsonl', 'matched.jsonl.manifest.json',
        'analysis-input.jsonl', 'analysis-input.jsonl.manifest.json', 'metadata-start.json',
        'details.jsonl', 'details.jsonl.manifest.json', 'summary.json', 'summary.csv', 'source-end.json'];

    public function __construct(private readonly ResultStore $writer) {}

    public function publish(string $stage, string $destination, array $expected, array $summary): array
    {
        if (array_keys($expected) !== self::INVENTORY) {
            throw new RuntimeException('Incomplete grade analysis inventory.');
        }
        $this->writer->verifyGenerated($stage, $expected, $summary);
        $manifest = ['status' => 'GRADE_ANALYSIS_LOCKED', 'contract' => Contract::plan(), 'files' => $expected,
            'published_at' => gmdate(DATE_ATOM), 'generation_verification' => 'WRITE_TIME_SEALS_AND_STRICT_SUMMARY'];
        $manifestSeal = $this->writer->writeJson($stage, 'manifest.json', $manifest);
        $lockSeal = $this->writer->writeJson($stage, 'LOCKED.json', $manifestSeal['manifest.json']);
        $this->verify($stage);
        $this->writer->verifyGenerated($stage, $manifestSeal + $lockSeal);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot publish analysis without overwrite.');
        }

        return $manifest;
    }

    public function verify(string $path): array
    {
        if (realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('Unsafe analysis bundle.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/LOCKED.json'));
        $manifest = Files::json($path.'/manifest.json');
        if (($manifest['status'] ?? null) !== 'GRADE_ANALYSIS_LOCKED' || array_keys($manifest['files'] ?? []) !== self::INVENTORY) {
            throw new RuntimeException('Invalid analysis inventory.');
        }
        Files::same(Contract::plan(), $manifest['contract'], 'grade contract');
        $this->writer->verifyGenerated($path, $manifest['files']);
        Files::same(Contract::plan(), Files::json($path.'/contract.json'), 'saved grade contract');

        return $manifest;
    }

    public function writeCsv(string $stage, array $summary): array
    {
        $memory = fopen('php://temp', 'w+b');
        try {
            $columns = array_keys($summary['cells'][0]);
            fputcsv($memory, $columns, ',', '"', '');
            foreach ($summary['cells'] as $cell) {
                fputcsv($memory, array_map(static fn (mixed $value): mixed => is_array($value) ? Files::canonical($value) : $value, $cell), ',', '"', '');
            }
            rewind($memory);
            $bytes = stream_get_contents($memory);
        } finally {
            fclose($memory);
        }
        $seal = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $handle = fopen($stage.'/summary.csv', 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create summary CSV.');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Incomplete CSV write.');
            }
        } finally {
            fclose($handle);
        }
        Files::verify($stage.'/summary.csv', $seal);

        return ['summary.csv' => $seal];
    }
}
