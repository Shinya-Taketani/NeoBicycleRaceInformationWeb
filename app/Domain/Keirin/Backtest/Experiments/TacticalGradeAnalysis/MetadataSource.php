<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MetadataSource
{
    public function __construct(private readonly ReadOnlySession $session, private readonly ResultStore $writer) {}

    public function capture(string $matched, string $stage): array
    {
        return $this->session->run(function (array $settings) use ($matched, $stage): array {
            $digest = [];
            $expected = $this->writer->writeJsonl($stage, 'analysis-input.jsonl', $this->rows($matched, $digest));

            return ['settings' => $settings, 'digest' => $digest, 'expected' => $expected];
        });
    }

    public function verify(string $matched, array $expected): array
    {
        return $this->session->run(function (array $settings) use ($matched, $expected): array {
            $digest = [];
            foreach ($this->rows($matched, $digest) as $_) {
            }
            Files::same($expected, $digest, 'metadata START/END');

            return ['settings' => $settings, 'digest' => $digest, 'status' => 'UNCHANGED'];
        });
    }

    private function rows(string $matched, array &$digest): Generator
    {
        $hash = hash_init('sha256');
        $races = $entries = 0;
        $batch = [];
        foreach (JsonlArtifact::read($matched) as $row) {
            Contract::race($row['context']);
            $batch[] = $row;
            if (count($batch) === 100) {
                yield from $this->batch($batch, $hash, $races, $entries);
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield from $this->batch($batch, $hash, $races, $entries);
        }
        $digest = ['races' => $races, 'entries' => $entries, 'sha256' => hash_final($hash)];
    }

    private function batch(array $batch, \HashContext $hash, int &$races, int &$entries): Generator
    {
        $byRace = [];
        foreach (Contract::YEARS as $year) {
            $ids = array_column(array_column(array_filter($batch, fn (array $row): bool => $row['context']['year'] === $year), 'context'), 'race_id');
            if ($ids === []) {
                continue;
            }
            // Exact target IDs and date bounds are applied before reading any database rows.
            $rows = DB::table('races as r')->join('race_entries as e', 'e.race_id', '=', 'r.id')
                ->whereIn('r.id', $ids)->whereBetween('r.race_date', [$year.'-01-01', $year.'-12-31'])
                ->select(['r.id as race_id', 'r.race_date', 'r.entrant_count', 'r.race_type',
                    'e.id as entry_id', 'e.bike_number', 'e.player_id', 'e.grade'])->orderBy('r.id')->orderBy('e.id')->get();
            foreach ($rows as $row) {
                $byRace[(int) $row->race_id][(int) $row->entry_id] = (array) $row;
            }
        }
        foreach ($batch as $row) {
            $id = $row['context']['race_id'];
            $stored = $byRace[$id] ?? [];
            if (count($stored) !== count($row['targets'])) {
                throw new RuntimeException('Missing race or entrant count mismatch: '.$id);
            }
            $attributes = [];
            $seen = [];
            $raceType = null;
            foreach ($row['targets'] as $target) {
                $entry = $stored[$target['id']] ?? null;
                if ($entry === null || isset($seen[$target['id']]) || (int) $entry['bike_number'] !== $target['bike']
                    || $entry['race_date'] !== $row['race_date'] || (int) $entry['entrant_count'] !== count($stored)
                    || ($entry['player_id'] === null ? null : (int) $entry['player_id']) !== $target['player_id']) {
                    throw new RuntimeException('Historical entry identity mismatch: '.$id.':'.$target['id']);
                }
                $seen[$target['id']] = true;
                $raceType = $entry['race_type'];
                $attributes[] = ['race_entry_id' => $target['id'], 'bike_number' => $target['bike'], 'player_id' => $target['player_id']]
                    + Grade::classify($entry['grade']);
            }
            $metadata = ['year' => $row['context']['year'], 'race_id' => $id, 'race_date' => $row['race_date'],
                'entrant_count' => count($stored), 'race_type_raw' => $raceType, 'stage' => Grade::stage($raceType),
                'source' => 'race_entries.grade / PJ0315.sensyuTypeInfo.kyuhan', 'entries' => $attributes];
            hash_update($hash, Files::canonical($metadata)."\n");
            $races++;
            $entries += count($stored);
            yield $row + ['metadata' => $metadata];
        }
    }
}
