<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryInputDto;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\Contract;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\Reader;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceEntry;
use App\Models\RaceMeeting;
use DateTimeImmutable;
use RuntimeException;

class OfficialMethodHistoryRepository
{
    public function __construct(private readonly Reader $reader, private readonly RaceCategoryPolicy $categories) {}

    /** @return \Generator<OfficialMethodHistoryInputDto> */
    public function inputs(int $raceId, DateTimeImmutable $asOf): \Generator
    {
        $race = Race::query()->whereKey($raceId)->whereBetween('race_date', ['2022-01-01', '2025-12-31'])->firstOrFail();
        $date = $race->race_date->format('Y-m-d');
        $day = RaceDay::query()->whereKey($race->race_day_id)->whereDate('race_date', $date)->firstOrFail();
        $meeting = RaceMeeting::query()->whereKey($day->race_meeting_id)->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)->firstOrFail();
        $start = $meeting->starts_on->format('Y-m-d');
        $end = $meeting->ends_on->format('Y-m-d');
        $asOf = Contract::inputAsOf($asOf, $start);
        if ($this->categories->classify($race->race_type) !== RaceCategory::Men || $day->race_date->format('Y-m-d') !== $date
            || $meeting->source !== $race->source || (int) $meeting->racetrack_id !== (int) $race->racetrack_id
            || $date < $start || $date > $end || $start > $end) {
            throw new RuntimeException('Invalid official history target meeting/category/cutoff.');
        }
        $target = ['race_id' => $raceId, 'race_date' => $date, 'meeting_id' => (int) $meeting->id,
            'meeting_start' => $start, 'meeting_end' => $end, 'cutoff' => Contract::date($start)->format(DATE_ATOM)];
        $entries = RaceEntry::query()->where('race_id', $raceId)->orderBy('bike_number')->limit(10)->get();
        if ($entries->isEmpty() || $entries->count() > 9 || $entries->unique('bike_number')->count() !== $entries->count()
            || $entries->unique('external_player_id')->count() !== $entries->count()) {
            throw new RuntimeException('Invalid official history target entries.');
        }
        foreach ($entries as $entry) {
            if (preg_match('/^[0-9]{6}$/D', (string) $entry->external_player_id) !== 1 || $entry->bike_number < 1 || $entry->bike_number > 9) {
                throw new RuntimeException('Unknown target registration.');
            }
            yield new OfficialMethodHistoryInputDto($target, ['entry_id' => (int) $entry->id,
                'player_id' => $entry->player_id, 'external_player_id' => $entry->external_player_id, 'bike' => $entry->bike_number],
                $asOf, $this->history($target, $entry));
        }
    }

    private function history(array $target, RaceEntry $entry): \Generator
    {
        $query = RaceEntry::query()->select('race_entries.*', 'd.race_meeting_id as history_meeting_id')
            ->join('races as r', 'r.id', '=', 'race_entries.race_id')->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
            ->where('race_entries.external_player_id', $entry->external_player_id)
            ->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])->where('r.race_date', '<', $target['meeting_start'])
            ->where('r.id', '<>', $target['race_id'])
            ->where(fn ($q) => $q->whereNull('d.race_meeting_id')->orWhere('d.race_meeting_id', '<>', $target['meeting_id']))
            ->orderByDesc('r.race_date')->orderByRaw('r.scheduled_start_at DESC NULLS FIRST')->orderByDesc('r.id')->orderByDesc('race_entries.id');
        foreach ($query->lazy(100) as $pastEntry) {
            if ($entry->player_id !== null && $pastEntry->player_id !== null && (int) $entry->player_id !== (int) $pastEntry->player_id) {
                throw new RuntimeException('Conflicting internal player IDs in official history.');
            }
            $race = Race::query()->whereKey($pastEntry->race_id)->whereBetween('race_date', ['2022-01-01', '2025-12-31'])->firstOrFail();
            $category = $this->categories->classify($race->race_type);
            if ($category === RaceCategory::Girls) {
                continue;
            }
            $row = $category === RaceCategory::Men ? $this->reader->read($race, $pastEntry)
                : ['evidence_verified' => false, 'participation' => 'UNKNOWN', 'method' => null, 'method_missing' => false,
                    'issues' => ['UNKNOWN_RACE_CATEGORY']];
            $day = RaceDay::query()->whereKey($race->race_day_id)->whereDate('race_date', $race->race_date->format('Y-m-d'))->first();
            $meeting = $day === null ? null : RaceMeeting::query()->whereKey($day->race_meeting_id)
                ->whereDate('starts_on', '<=', $race->race_date->format('Y-m-d'))
                ->whereDate('ends_on', '>=', $race->race_date->format('Y-m-d'))->first();
            if ($meeting === null || $meeting->source !== $race->source || (int) $meeting->racetrack_id !== (int) $race->racetrack_id) {
                $row['issues'][] = 'MEETING_CONTEXT_UNVERIFIED';
            }
            yield $row + ['race_id' => (int) $race->id, 'race_date' => $race->race_date->format('Y-m-d'),
                'race_status' => $race->result_status, 'meeting_id' => $pastEntry->history_meeting_id === null ? null : (int) $pastEntry->history_meeting_id,
                'scheduled_start_at' => $race->scheduled_start_at?->format(DATE_ATOM), 'entry_id' => (int) $pastEntry->id,
                'player_id' => $pastEntry->player_id, 'external_player_id' => $pastEntry->external_player_id, 'bike' => $pastEntry->bike_number];
        }
    }
}
