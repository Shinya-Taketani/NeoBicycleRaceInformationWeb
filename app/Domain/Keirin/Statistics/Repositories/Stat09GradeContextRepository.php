<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Scraping\Support\GradeNormalizer;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Stat09GradeContextDto;
use App\Domain\Keirin\Statistics\Support\Stat09GradeContract as Contract;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class Stat09GradeContextRepository
{
    /** @param list<HistoricalRaceDto> $histories @return array<int,Stat09GradeContextDto> */
    public function forHistories(array $histories): array
    {
        $expected = [];
        foreach ($histories as $event) {
            $identity = [$event->raceId, $event->playerId];
            if (isset($expected[$event->raceEntryId]) && $expected[$event->raceEntryId] !== $identity) {
                throw new UnexpectedValueException('Conflicting historical grade entry identity.');
            }
            $expected[$event->raceEntryId] = $identity;
        }
        ksort($expected);
        $result = [];
        foreach (array_chunk(array_keys($expected), 250) as $ids) {
            $rows = DB::table('race_entries as e')->leftJoin('races as r', function ($join): void {
                $join->on('r.id', '=', 'e.race_id')->whereBetween('r.race_date', ['2022-01-01', '2025-12-31']);
            })->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
                ->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')
                ->whereIn('e.id', $ids)->select(['e.id', 'e.race_id', 'e.player_id', 'e.grade as entry_grade', 'e.fetched_at as entry_fetched_at',
                    'r.id as found_race_id', 'r.race_date', 'r.grade as race_grade', 'r.racetrack_id as race_track_id', 'r.race_day_id',
                    'r.last_fetched_at as race_last_fetched_at', 'd.id as day_id', 'd.race_date as day_date', 'd.race_meeting_id',
                    'd.last_fetched_at as day_last_fetched_at', 'm.id as meeting_id', 'm.grade as meeting_grade', 'm.starts_on', 'm.ends_on',
                    'm.racetrack_id as meeting_track_id', 'm.last_fetched_at as meeting_last_fetched_at'])->orderBy('e.id')->get()->keyBy('id');
            foreach ($ids as $id) {
                [$race, $player] = $expected[$id];
                $row = $rows->get($id);
                if ($row !== null && ((int) $row->race_id !== $race || $row->player_id === null || (int) $row->player_id !== $player)) {
                    throw new UnexpectedValueException('Historical grade race/entry/player binding mismatch.');
                }
                $result[$id] = $this->project($id, $race, $player, $row);
            }
        }

        return $result;
    }

    private function project(int $entry, int $race, int $player, ?object $r): Stat09GradeContextDto
    {
        $entryReasons = $meetingReasons = [];
        $entryGrade = $meetingGrade = 'UNKNOWN';
        if ($r === null || $r->found_race_id === null) {
            $entryReasons[] = $meetingReasons[] = $r === null ? 'HISTORY_ENTRY_NOT_FOUND' : 'HISTORY_RACE_UNAVAILABLE';
        } else {
            $normalized = GradeNormalizer::normalize($r->entry_grade);
            if (in_array($normalized, Contract::ENTRY_GRADES, true)) {
                $entryGrade = $normalized;
            } else {
                $entryReasons[] = $r->entry_grade === null ? 'ENTRY_GRADE_NULL'
                    : (trim($r->entry_grade) === '' ? 'ENTRY_GRADE_EMPTY' : 'ENTRY_GRADE_UNKNOWN_OR_UNSUPPORTED');
            }
            if ($r->race_day_id === null) {
                $meetingReasons[] = 'MEETING_RELATION_MISSING';
            } elseif ($r->day_id === null) {
                $meetingReasons[] = 'RACE_DAY_NOT_FOUND';
            } elseif ($r->race_meeting_id === null || $r->meeting_id === null) {
                $meetingReasons[] = 'MEETING_NOT_FOUND';
            }
            if ($r->day_id !== null && (! $this->date($r->day_date) || $r->day_date !== $r->race_date)) {
                $meetingReasons[] = 'RACE_DAY_DATE_MISMATCH';
            }
            if ($r->meeting_id !== null) {
                if (! $this->date($r->starts_on) || ! $this->date($r->ends_on) || ! $this->date($r->race_date)
                    || $r->starts_on > $r->ends_on || $r->race_date < $r->starts_on || $r->race_date > $r->ends_on) {
                    $meetingReasons[] = 'MEETING_PERIOD_UNVERIFIED';
                }
                if ($r->race_track_id === null || $r->meeting_track_id === null || (int) $r->race_track_id !== (int) $r->meeting_track_id) {
                    $meetingReasons[] = 'MEETING_TRACK_MISMATCH_OR_MISSING';
                }
                $meetingGrade = Classification::normalize($r->meeting_grade);
                if ($meetingGrade === 'UNKNOWN') {
                    $meetingReasons[] = 'MEETING_GRADE_UNKNOWN_OR_MISSING';
                }
                $raceGrade = Classification::normalize($r->race_grade);
                if ($raceGrade !== 'UNKNOWN' && $meetingGrade !== 'UNKNOWN' && $raceGrade !== $meetingGrade) {
                    $meetingReasons[] = 'CONFLICTING_MEETING_GRADE';
                }
            }
            if ($meetingReasons !== []) {
                $meetingGrade = 'UNKNOWN';
            }
        }

        return new Stat09GradeContextDto($entry, $race, $player, $entryGrade, $meetingGrade,
            ['entry_grade' => $r?->entry_grade, 'race_grade' => $r?->race_grade, 'meeting_grade' => $r?->meeting_grade,
                'race_date' => $r?->race_date, 'day_date' => $r?->day_date, 'starts_on' => $r?->starts_on, 'ends_on' => $r?->ends_on,
                'race_track_id' => $r?->race_track_id, 'meeting_track_id' => $r?->meeting_track_id],
            ['projection_contract' => Contract::PROJECTION, 'normalization_contract' => Contract::NORMALIZATION,
                'entry_found' => $r !== null, 'race_found' => $r !== null && $r->found_race_id !== null,
                'entry_id' => $entry, 'race_id' => $race, 'player_id' => $player,
                'race_day_id' => $r?->race_day_id, 'day_id' => $r?->day_id, 'meeting_id' => $r?->meeting_id,
                'entry_fetched_at' => $r?->entry_fetched_at, 'race_last_fetched_at' => $r?->race_last_fetched_at,
                'day_last_fetched_at' => $r?->day_last_fetched_at, 'meeting_last_fetched_at' => $r?->meeting_last_fetched_at,
                'time_scope' => 'ROW_FETCH_TIMES_NOT_ATTRIBUTE_OBSERVATION_OR_PUBLICATION'],
            ['entry_grade' => $entryReasons, 'meeting_grade' => $meetingReasons]);
    }

    private function date(?string $value): bool
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
