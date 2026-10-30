<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Scraping\Support\GradeNormalizer;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\SimilarityConditionsDto;
use App\Domain\Keirin\Statistics\Support\RaceStageNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SimilarityConditionsRepository
{
    public const VERSION = 'SIX-CONDITIONS-SAVED-ROSTER-PROJECTION-v1';

    public function __construct(private readonly RaceStageNormalizer $stages) {}

    public function forTarget(Batch02TargetEntryDto $target, array $histories): array
    {
        $ids = [$target->raceEntryId => [$target->raceId, $target->playerId, $target->bikeNumber]];
        foreach ($histories as $event) {
            $ids[$event->raceEntryId] = [$event->raceId, $event->playerId, null];
        }
        $result = [];
        foreach (array_chunk(array_keys($ids), 250) as $chunk) {
            $rows = DB::table('race_entries as e')->join('races as r', 'r.id', '=', 'e.race_id')
                ->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
                ->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')
                ->whereIn('e.id', $chunk)->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])
                ->select(['e.id', 'e.race_id', 'e.player_id', 'e.bike_number', 'e.grade', 'e.fetched_at',
                    'r.race_date', 'r.race_type', 'r.entrant_count', 'r.scheduled_start_at', 'r.grade as race_grade', 'r.racetrack_id',
                    'd.id as day_id', 'd.race_date as day_date', 'd.day_number', 'd.race_meeting_id',
                    'm.starts_on', 'm.ends_on', 'm.duration_days', 'm.grade as meeting_grade', 'm.racetrack_id as meeting_track'])
                ->selectRaw('(SELECT count(*) FROM race_entries c WHERE c.race_id=e.race_id) as stored_roster_count')->orderBy('e.id')->get();
            foreach ($rows as $row) {
                [$raceId, $playerId, $bike] = $ids[(int) $row->id];
                if ((int) $row->race_id !== $raceId || ($row->player_id === null ? null : (int) $row->player_id) !== $playerId
                    || ($bike !== null && (int) $row->bike_number !== $bike)) {
                    throw new RuntimeException('Similarity condition entrant binding mismatch.');
                }
                $result[(int) $row->id] = $this->project($row);
            }
        }
        if (! isset($result[$target->raceEntryId])) {
            throw new RuntimeException('Missing fixed target similarity conditions.');
        }

        return $result;
    }

    private function project(object $r): SimilarityConditionsDto
    {
        $reasons = [];
        $meetingValid = $r->race_meeting_id !== null && $r->day_date === $r->race_date && $r->starts_on !== null && $r->ends_on !== null
            && $r->starts_on <= $r->race_date && $r->ends_on >= $r->race_date && (int) $r->meeting_track === (int) $r->racetrack_id;
        if (! $meetingValid) {
            $reasons[] = 'MEETING_CONTEXT_UNVERIFIED';
        }
        $field = $this->positive($r->entrant_count);
        $field = $field !== null && $field >= 5 && $field <= 9 && $field === (int) $r->stored_roster_count ? $field : null;
        if ($field === null) {
            $reasons[] = 'SAVED_ROSTER_SIZE_INCONSISTENT';
        }
        $stage = $this->stages->normalize($r->race_type)->value;
        $grade = GradeNormalizer::normalize($r->grade);
        $meetingGrade = $meetingValid ? Classification::normalize($r->meeting_grade) : 'UNKNOWN';
        $raceGrade = Classification::normalize($r->race_grade);
        if ($raceGrade !== 'UNKNOWN' && $meetingGrade !== 'UNKNOWN' && $raceGrade !== $meetingGrade) {
            $meetingGrade = 'UNKNOWN';
            $reasons[] = 'CONFLICTING_MEETING_GRADE';
        }
        $day = $this->positive($r->day_number);
        $day = $meetingValid && $day !== null && ($r->duration_days === null || $day <= ($this->positive($r->duration_days) ?? 0)) ? $day : null;
        $start = $r->scheduled_start_at === null ? null : (new DateTimeImmutable($r->scheduled_start_at))->setTimezone(new DateTimeZone('Asia/Tokyo'));
        if ($start !== null && $start->format('Y-m-d') !== $r->race_date) {
            $start = null;
            $reasons[] = 'SCHEDULED_DATE_MISMATCH';
        }
        $values = ['field_size' => $field, 'race_stage' => in_array($stage, ['UNKNOWN', 'OTHER'], true) ? null : $stage,
            'meeting_grade' => $meetingGrade === 'UNKNOWN' ? null : $meetingGrade,
            'entry_grade' => in_array($grade, ['SS', 'S1', 'S2', 'S3', 'A1', 'A2', 'A3', 'A4', 'B1', 'B2'], true) ? $grade : null,
            'day_number' => $day, 'start_minute_jst' => $start === null ? null : (int) $start->format('G') * 60 + (int) $start->format('i') + (float) $start->format('s.u') / 60];

        return new SimilarityConditionsDto($values,
            ['entrant_count' => $r->entrant_count, 'stored_roster_count' => $r->stored_roster_count, 'race_type' => $r->race_type,
                'meeting_grade' => $r->meeting_grade, 'race_grade' => $r->race_grade, 'entry_grade' => $r->grade, 'day_number' => $r->day_number,
                'scheduled_start_at' => $r->scheduled_start_at],
            ['entry_id' => (int) $r->id, 'race_id' => (int) $r->race_id, 'meeting_id' => $r->race_meeting_id === null ? null : (int) $r->race_meeting_id,
                'meeting_context_verified' => $meetingValid,
                'meeting_start' => $meetingValid ? $r->starts_on : null, 'fetched_at' => $r->fetched_at,
                'field_size_basis' => 'SAVED_RACE_DETAIL_ROSTER_ENTRY_COUNT_NOT_ACTUAL_STARTERS',
                'projection_version' => self::VERSION, 'stage_normalizer' => RaceStageNormalizer::VERSION,
                'meeting_grade_normalizer' => 'TACTICAL_MEETING_GRADE_CLASSIFICATION_MAP', 'entry_grade_normalizer' => GradeNormalizer::class], $reasons);
    }

    private function positive(mixed $value): ?int
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && (float) $value <= PHP_INT_MAX)
            ? (int) $value : null;
    }
}
