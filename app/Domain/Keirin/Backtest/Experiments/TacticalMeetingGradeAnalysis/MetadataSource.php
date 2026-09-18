<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis;

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

    public function capture(string $input, string $stage): array
    {
        return $this->session->run(function (array $settings) use ($input, $stage): array {
            $digest = [];
            $expected = $this->writer->writeJsonl($stage, 'metadata.jsonl', $this->rows($input, $digest));

            return compact('settings', 'digest', 'expected');
        });
    }

    public function verify(string $input, array $expected): array
    {
        return $this->session->run(function (array $settings) use ($input, $expected): array {
            $digest = [];
            foreach ($this->rows($input, $digest) as $_) {
            }
            Files::same($expected, $digest, 'meeting metadata START/END');

            return ['settings' => $settings, 'digest' => $digest, 'status' => 'UNCHANGED'];
        });
    }

    private function rows(string $input, array &$digest): Generator
    {
        $hash = hash_init('sha256');
        $count = 0;
        $batch = [];
        foreach (JsonlArtifact::read($input) as $old) {
            $target = ['year' => $old['context']['year'], 'race_id' => $old['context']['race_id'],
                'race_date' => $old['race_date'], 'entrant_count' => count($old['context']['entries'])];
            Classification::validate($target);
            $batch[] = $target;
            if (count($batch) === 100) {
                yield from $this->batch($batch, $hash, $count);
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield from $this->batch($batch, $hash, $count);
        }
        $digest = ['races' => $count, 'sha256' => hash_final($hash)];
    }

    private function batch(array $targets, \HashContext $hash, int &$count): Generator
    {
        $found = [];
        foreach ([2024, 2025] as $year) {
            $ids = array_column(array_filter($targets, fn ($t) => $t['year'] === $year), 'race_id');
            if ($ids === []) {
                continue;
            }
            // Only fixed target IDs and development dates; never query results or player profiles.
            $rows = DB::table('races as r')->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
                ->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')->leftJoin('racetracks as t', 't.id', '=', 'm.racetrack_id')
                ->whereIn('r.id', $ids)->whereBetween('r.race_date', [$year.'-01-01', $year.'-12-31'])
                ->select(['r.id as race_id', 'r.race_date', 'r.race_day_id', 'r.racetrack_id', 'r.grade as race_grade_raw',
                    'r.race_type as race_type_raw', 'r.entrant_count', 'd.race_date as day_date', 'm.id as meeting_id',
                    'm.grade as meeting_grade_raw', 'm.starts_on', 'm.ends_on', 'm.racetrack_id as meeting_track_id',
                    't.external_track_id as track_code', 't.name as track_name'])->orderBy('r.id')->get();
            foreach ($rows as $row) {
                $found[(int) $row->race_id] = (array) $row;
            }
        }
        foreach ($targets as $target) {
            $row = $found[$target['race_id']] ?? throw new RuntimeException('Missing target race.');
            foreach (['race_id', 'race_day_id', 'racetrack_id', 'entrant_count', 'meeting_id', 'meeting_track_id'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
            if ($row['race_date'] !== $target['race_date'] || $row['entrant_count'] !== $target['entrant_count']
                || ($row['day_date'] !== null && $row['day_date'] !== $row['race_date'])
                || ($row['meeting_id'] !== null && ($row['racetrack_id'] !== $row['meeting_track_id']
                    || $row['race_date'] < $row['starts_on'] || $row['race_date'] > $row['ends_on']))) {
                throw new RuntimeException('Historical meeting/date/track mismatch.');
            }
            $row = ['year' => $target['year']] + $row;
            Classification::validate($row);
            hash_update($hash, Files::canonical($row)."\n");
            $count++;
            yield $row;
        }
    }
}
