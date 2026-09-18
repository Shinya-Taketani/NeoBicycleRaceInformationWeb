<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;

class Store
{
    public const INVENTORY = ['contract.json', 'sources.json', 'code.json', 'metadata.jsonl', 'metadata.jsonl.manifest.json',
        'metadata-start.json', 'meetings.json', 'analysis-input.jsonl', 'analysis-input.jsonl.manifest.json',
        'details.jsonl', 'details.jsonl.manifest.json', 'summary.json', 'summary.csv', 'source-end.json'];

    public function __construct(private readonly ResultStore $writer, private readonly AnalysisStore $csv) {}

    public function publish(string $stage, string $destination, array $expected, array $summary): array
    {
        if (array_keys($expected) !== self::INVENTORY) {
            throw new RuntimeException('Incomplete meeting analysis inventory.');
        }
        $this->writer->verifyGenerated($stage, $expected, $summary);
        $manifest = ['status' => 'MEETING_ANALYSIS_LOCKED', 'contract' => Contract::plan(), 'files' => $expected,
            'published_at' => gmdate(DATE_ATOM), 'verification' => 'WRITE_TIME_SEALS_AND_STRICT_SUMMARY'];
        $seal = $this->writer->writeJson($stage, 'manifest.json', $manifest);
        $seal += $this->writer->writeJson($stage, 'LOCKED.json', $seal['manifest.json']);
        $this->verify($stage);
        $this->writer->verifyGenerated($stage, $seal);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot publish without overwrite.');
        }

        return $manifest;
    }

    public function verify(string $path): array
    {
        if (realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('Unsafe meeting bundle.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/LOCKED.json'));
        $manifest = Files::json($path.'/manifest.json');
        if (($manifest['status'] ?? null) !== 'MEETING_ANALYSIS_LOCKED' || array_keys($manifest['files'] ?? []) !== self::INVENTORY) {
            throw new RuntimeException('Invalid meeting bundle inventory.');
        }
        Files::same(Contract::plan(), $manifest['contract'], 'meeting contract');
        $this->writer->verifyGenerated($path, $manifest['files']);
        Files::same(Contract::plan(), Files::json($path.'/contract.json'), 'saved meeting contract');

        return $manifest;
    }

    public function writeCsv(string $stage, array $summary): array
    {
        $rows = [];
        foreach ($summary['cells'] as $cell) {
            $row = array_diff_key($cell, ['metrics' => true]);
            foreach ($cell['metrics'] as $metric => $values) {
                foreach ($values as $key => $value) {
                    $row[$metric.'_'.$key] = $value;
                }
            }
            $rows[] = $row;
        }

        return $this->csv->writeCsv($stage, ['cells' => $rows]);
    }
}
