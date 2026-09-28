<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract;
use App\Domain\Keirin\Statistics\AgariC1Input\Sources;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Exact;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Brick\Math\BigRational;

/** Synthetic only: no real rider, race, outcome or acquisition record. */
final class AgariC1InputFixture
{
    public static function race(int $year = 2024, int $id = 1): array
    {
        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $entry = ['id' => $id * 10 + $i, 'bike' => $i, 'raw' => 80.0, 'stat01_rank' => 1,
                'anchor' => 0.0, 'anchor_status' => 'ZERO_VARIANCE', 'signals' => array_fill(0, 12, 0.0),
                'history' => [0, 0, 0, 0], 'history_status' => 'AVAILABLE'];
            if ($year <= 2023) {
                $entry += ['labels' => [1, 1, 1], 'rank' => 1, 'status' => 'FINISHED'];
            }
            $entries[] = $entry;
        }

        return ['year' => $year, 'race_id' => $id, 'entries' => $entries];
    }

    public static function context(array $race, array $entry): array
    {
        return ['source' => 'keirin_jp', 'external_player_id' => sprintf('%06d', $entry['bike']),
            'race_id' => $race['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike'],
            'race_date' => $race['year'].'-08-01', 'meeting' => ['meeting_id' => $race['race_id'],
                'starts_on' => $race['year'].'-08-01', 'ends_on' => $race['year'].'-08-03'],
            'race_type' => 'S級予選', 'observed_at' => null, 'source_record_id' => 'synthetic-entry-snapshot:'.$entry['id']];
    }

    public static function meeting(int $id = 101, ?string $value = '1/3', string $start = '2022-07-01', string $end = '2022-07-03', string $external = '000001'): array
    {
        return ['id' => hash('sha256', $external.':'.$id), 'source' => 'keirin_jp', 'external_player_id' => $external,
            'measurement_definition_id' => Contract::DEFINITION, 'race_class' => 'S_CLASS', 'history_context_eligible' => true,
            'first_observed_date' => $start, 'meeting' => ['meeting_id' => $id, 'starts_on' => $start, 'ends_on' => $end],
            'context_flags' => [], 'adopted_races' => $value === null ? 0 : 2, 'exclusion_reasons' => [],
            'meeting_percentile_mean' => $value === null ? null : Exact::value(BigRational::of($value))];
    }

    public static function bundle(string $root, ?array $races = null, ?array $meetings = null, ?array $contexts = null): Sources
    {
        foreach (['c1', 'history', 'context'] as $dir) {
            mkdir($root.'/'.$dir, 0700);
        }
        $races ??= array_map(fn ($y) => self::race($y, $y), Contract::YEARS);
        $years = [];
        foreach (Contract::YEARS as $year) {
            $rows = array_values(array_filter($races, fn ($r) => $r['year'] === $year));
            $years[$year] = ['inputs' => ['rows' => count($rows), ...Artifacts::write($root.'/c1', 'inputs-'.$year.'.jsonl',
                array_map(fn ($r) => Files::canonical($r)."\n", $rows))]];
        }
        Artifacts::json($root.'/c1', 'manifest.json', ['calculation_version' => Contract::C1_VERSION, 'manifests' => $years]);
        $seal = Artifacts::write($root.'/history', 'player-meetings.jsonl', array_map(fn ($r) => Files::canonical($r)."\n", $meetings ?? [self::meeting()]));
        Artifacts::publish($root.'/history', ['version' => 'STAT35-PLAYER-HISTORY-v1', 'kind' => 'PLAYER_HISTORY',
            'source' => 'keirin_jp', 'from' => '2022-01-01', 'to' => '2025-12-31', 'historical_as_of_available' => false,
            'files' => ['player-meetings.jsonl' => $seal]]);
        if ($contexts === null) {
            $contexts = [];
            foreach ($races as $race) {
                foreach ($race['entries'] as $entry) {
                    $contexts[] = self::context($race, $entry);
                }
            }
        }
        $seal = Artifacts::write($root.'/context', 'entry-context.jsonl', array_map(fn ($r) => Files::canonical($r)."\n", $contexts));
        Artifacts::publish($root.'/context', ['version' => Contract::CONTEXT_VERSION, 'origin' => 'SAVED_ENTRY_AND_MEETING_METADATA',
            'historical_as_of_available' => false, 'files' => ['entry-context.jsonl' => $seal]]);

        return new Sources(['c1' => Files::identity($root.'/c1/manifest.json'), 'history' => Files::identity($root.'/history/manifest.json')]);
    }
}
