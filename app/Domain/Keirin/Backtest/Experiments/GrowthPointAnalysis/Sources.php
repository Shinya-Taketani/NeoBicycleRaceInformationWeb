<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Store;
use Generator;
use RuntimeException;

class Sources
{
    public function __construct(private readonly Store $meetings) {}

    public function open(string $bundle): array
    {
        $manifest = $this->meetings->verify($bundle);
        if (Files::identity($bundle.'/manifest.json')['sha256'] !== '61d1718f29f1683a34698a40f25d89273a52f976c4d475641b6bfc4fd14e05fc') {
            throw new RuntimeException('Expected fixed 50078-race meeting analysis.');
        }
        $old = Files::json($bundle.'/sources.json');
        $files = $old['files'];
        foreach ($manifest['files'] as $file => $seal) {
            $files[$bundle.'/'.$file] = $seal;
        }
        foreach (['manifest.json', 'LOCKED.json'] as $file) {
            $files[$bundle.'/'.$file] = Files::identity($bundle.'/'.$file);
        }
        $source = ['input' => $old['input'], 'meeting_details' => $bundle.'/details.jsonl', 'files' => $files,
            'expected_counts' => [2024 => 25212, 2025 => 24866]];
        $this->verify($source);

        return $source;
    }

    public function verify(array $source): void
    {
        foreach ($source['files'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }

    public function rows(array $source): Generator
    {
        $meeting = JsonlArtifact::read($source['meeting_details']);
        $meeting->rewind();
        $counts = [];
        foreach (JsonlArtifact::read($source['input']) as $row) {
            $year = $row['context']['year'];
            if (! in_array($year, Contract::YEARS, true)) {
                throw new RuntimeException('Forbidden cohort year.');
            }
            $m = $meeting->current();
            if (! $meeting->valid() || $m['race_id'] !== $row['context']['race_id'] || $m['year'] !== $year
                || $m['race_date'] !== $row['race_date'] || count($row['targets']) !== count($row['context']['entries'])) {
                throw new RuntimeException('Fixed cohort and meeting alignment mismatch.');
            }
            $targets = array_column($row['targets'], null, 'id');
            if (count($targets) !== count($row['targets'])
                || ! in_array($row['decision']['primary_position_1_bike'], array_column($row['context']['entries'], 'bike'), true)
                || ! in_array($m['grade'], ['GP', 'G1', 'G2', 'G3', 'F1', 'F2', 'UNKNOWN'], true)
                || ! in_array($m['race_class'], ['S_CLASS', 'A1_A2', 'A_CHALLENGE', 'UNKNOWN'], true)) {
                throw new RuntimeException('Invalid fixed cohort classification/decision/entries.');
            }
            foreach ($row['context']['entries'] as $entry) {
                if (($targets[$entry['id']]['bike'] ?? null) !== $entry['bike']) {
                    throw new RuntimeException('Saved target identity mismatch.');
                }
            }
            $counts[$year] = ($counts[$year] ?? 0) + 1;
            yield ['context' => $row['context'], 'targets' => $row['targets'], 'decision' => $row['decision'],
                'date' => $row['race_date'], 'grade' => $m['grade'], 'class' => $m['race_class']];
            $meeting->next();
        }
        if ($meeting->valid()) {
            throw new RuntimeException('Extra meeting rows.');
        }
        Files::same($source['expected_counts'], $counts, 'growth cohort counts');
    }
}
