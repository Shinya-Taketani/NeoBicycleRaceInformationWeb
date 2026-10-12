<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Statistics\Calculators\Stat20Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat20RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20SourceInputDto;
use DateTimeImmutable;

final class Stat20Fixture
{
    public static function race(int $count = 7, int $raceId = 42): Stat20RaceInputDto
    {
        // Synthetic quality metadata, not actual upstream calculations or site data.
        $sources = $runs = $identities = $participants = [];
        $asOf = new DateTimeImmutable('2024-01-02T11:55:00+09:00');
        $fetched = new DateTimeImmutable('2024-01-02T10:00:00+09:00');
        for ($bike = 1; $bike <= $count; $bike++) {
            $entry = $raceId * 10 + $bike;
            $identities[$entry] = ['race_id' => $raceId, 'player_id' => 1000 + $bike, 'bike_number' => $bike];
            $participants[] = ['race_entry_id' => $entry, 'player_id' => 1000 + $bike, 'bike_number' => $bike,
                'stat01_input_hash' => hash('sha256', $raceId.':STAT-01:'.$bike)];
        }
        foreach (Stat20Calculator::SOURCE_STAT_CODES as $index => $code) {
            $runs[$code] = $index + 1;
            for ($bike = 1; $bike <= ($code === 'STAT-41' ? 1 : $count); $bike++) {
                $entry = $raceId * 10 + $bike;
                $sources[] = new Stat20SourceInputDto(
                    $raceId * 1000 + $index * 10 + $bike, $index + 1, $code, $code.'-existing-db-v1',
                    $code === 'STAT-41' ? 'RACE' : 'RACE_ENTRY',
                    $code === 'STAT-41' ? 'race:'.$raceId : 'race_entry:'.$entry,
                    $raceId, $code === 'STAT-41' ? null : $entry, $code === 'STAT-41' ? null : 1000 + $bike,
                    $code === 'STAT-41' ? null : $bike, 'VALID', 'FULL', $asOf, $fetched,
                    ['ENTRANT_COUNT' => $count, 'RACE_SCORE_RAW' => 90 - $bike, 'RACE_SCORE_AVAILABLE' => true,
                        'DAY_WINDOWS' => ['365' => ['sample_count' => 2, 'window_complete' => false,
                            'history_start_at' => '2023-12-01T12:00:00+09:00', 'history_end_at' => '2023-12-31T12:00:00+09:00']]],
                    ['stat01_run_id' => 1, 'stat01_input_hash' => hash('sha256', $raceId.':STAT-01:'.$bike),
                        'participant_snapshot' => $code === 'STAT-41' ? $participants : [],
                        'history_max_at' => '2023-12-31T12:00:00+09:00', 'quality_reasons' => [],
                        'history_result_mode' => 'BACKFILLED_FINAL_RESULT', 'unavailable_components' => []],
                    hash('sha256', $raceId.':'.$code.':'.$bike),
                );
            }
        }

        return new Stat20RaceInputDto($raceId, new DateTimeImmutable('2024-01-02'),
            new DateTimeImmutable('2024-01-02T12:00:00+09:00'), $runs, $sources, $identities);
    }

    public static function change(Stat20RaceInputDto $race, string $code, int $bike, array $values): Stat20RaceInputDto
    {
        $sources = array_map(fn ($s) => $s->statCode === $code && ($s->bikeNumber === $bike || $code === 'STAT-41')
            ? new Stat20SourceInputDto(...array_replace(get_object_vars($s), $values)) : $s, $race->sources);

        return new Stat20RaceInputDto(...array_replace(get_object_vars($race), ['sources' => $sources]));
    }
}
