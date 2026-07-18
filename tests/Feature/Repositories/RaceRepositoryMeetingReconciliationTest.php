<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Domain\Keirin\Scraping\DTO\RaceDayMetadataPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceDayParameterDto;
use App\Domain\Keirin\Scraping\DTO\RaceScheduleItemDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Models\Player;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceEntry;
use App\Models\RaceMeeting;
use App\Models\RacePayout;
use App\Models\RaceResult;
use App\Models\Racetrack;
use App\Repositories\RaceRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaceRepositoryMeetingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_three_day_meeting_preserves_day_ids_and_is_idempotent(): void
    {
        $meeting = $this->meeting(['20260601', '20260602', '20260603']);
        $ids = RaceDay::query()->where('race_meeting_id', $meeting->id)->orderBy('race_date')->pluck('id')->all();
        $externalIds = RaceDay::query()->where('race_meeting_id', $meeting->id)->orderBy('race_date')->pluck('external_race_day_id')->all();

        $this->repository()->updateMeetingDayParameters($meeting, $this->metadata(['20260601', '20260602', '20260603']), $this->fetchedAt());
        $this->repository()->updateMeetingDayParameters($meeting, $this->metadata(['20260601', '20260602', '20260603']), $this->fetchedAt());

        $days = RaceDay::query()->where('race_meeting_id', $meeting->id)->orderBy('race_date')->get();
        $this->assertSame($ids, $days->pluck('id')->all());
        $this->assertSame($externalIds, $days->pluck('external_race_day_id')->all());
        $this->assertSame([1, 2, 3], $days->pluck('day_number')->all());
        $this->assertSame(['day-20260601', 'day-20260602', 'day-20260603'], $days->pluck('encrypted_parameter')->all());
        $this->assertSame('https://example.test/race-list', $meeting->refresh()->race_list_url);
        $this->assertSame(3, $meeting->duration_days);
    }

    public function test_meeting_started_in_previous_month_adds_missing_days(): void
    {
        $meeting = $this->meeting(['20260601']);
        $preservedDay = RaceDay::query()->where('race_meeting_id', $meeting->id)->firstOrFail();
        $preservedId = $preservedDay->id;
        $this->relatedRaceData($meeting, $preservedDay);
        $counts = $this->protectedTableCounts();

        $this->repository()->updateMeetingDayParameters($meeting, $this->metadata(['20260530', '20260531', '20260601']), $this->fetchedAt());

        $days = RaceDay::query()->where('race_meeting_id', $meeting->id)->orderBy('race_date')->get();
        $this->assertSame(['20260530', '20260531', '20260601'], $days->map(fn (RaceDay $day): string => $day->race_date->format('Ymd'))->all());
        $this->assertSame($preservedId, $days->last()->id);
        $this->assertSame('meeting-1:date:20260530', $days->first()->external_race_day_id);
        $this->assertSame('2026-05-30', $meeting->refresh()->starts_on->format('Y-m-d'));
        $this->assertSame('2026-06-01', $meeting->ends_on->format('Y-m-d'));
        $this->assertSame($counts, $this->protectedTableCounts());
        $this->assertSame($preservedId, Race::query()->firstOrFail()->race_day_id);
    }

    public function test_meeting_continued_into_next_month_adds_missing_days(): void
    {
        $meeting = $this->meeting(['20260630']);
        $preservedId = RaceDay::query()->where('race_meeting_id', $meeting->id)->value('id');

        $this->repository()->updateMeetingDayParameters($meeting, $this->metadata(['20260630', '20260701', '20260702']), $this->fetchedAt());

        $days = RaceDay::query()->where('race_meeting_id', $meeting->id)->orderBy('race_date')->get();
        $this->assertSame(['20260630', '20260701', '20260702'], $days->map(fn (RaceDay $day): string => $day->race_date->format('Ymd'))->all());
        $this->assertSame($preservedId, $days->first()->id);
        $this->assertSame('meeting-1:date:20260702', $days->last()->external_race_day_id);
        $this->assertSame('2026-06-30', $meeting->refresh()->starts_on->format('Y-m-d'));
        $this->assertSame('2026-07-02', $meeting->ends_on->format('Y-m-d'));
    }

    public function test_database_only_day_without_races_is_removed_and_days_are_renumbered(): void
    {
        $meeting = $this->meeting(['20260601', '20260602', '20260604']);
        $firstDayId = RaceDay::query()->whereDate('race_date', '2026-06-01')->value('id');

        $this->repository()->updateMeetingDayParameters($meeting, $this->metadata(['20260601', '20260602', '20260603']), $this->fetchedAt());

        $days = RaceDay::query()->where('race_meeting_id', $meeting->id)->orderBy('race_date')->get();
        $this->assertSame(['20260601', '20260602', '20260603'], $days->map(fn (RaceDay $day): string => $day->race_date->format('Ymd'))->all());
        $this->assertSame([1, 2, 3], $days->pluck('day_number')->all());
        $this->assertSame($firstDayId, $days->first()->id);
        $this->assertDatabaseMissing('race_days', ['race_meeting_id' => $meeting->id, 'race_date' => '2026-06-04']);
    }

    public function test_database_only_day_with_races_rolls_back_every_change_and_preserves_related_data(): void
    {
        $meeting = $this->meeting(['20260601', '20260604']);
        $protectedDay = RaceDay::query()->whereDate('race_date', '2026-06-04')->firstOrFail();
        $this->relatedRaceData($meeting, $protectedDay);
        $counts = $this->protectedTableCounts();

        try {
            $this->repository()->updateMeetingDayParameters($meeting, $this->metadata(['20260601', '20260602', '20260603']), $this->fetchedAt());
            $this->fail('ParserException was not thrown.');
        } catch (ParserException $exception) {
            $this->assertStringContainsString('had related races', $exception->getMessage());
        }

        $this->assertSame($counts, $this->protectedTableCounts());
        $this->assertSame(['20260601', '20260604'], RaceDay::query()
            ->where('race_meeting_id', $meeting->id)
            ->orderBy('race_date')
            ->get()
            ->map(fn (RaceDay $day): string => $day->race_date->format('Ymd'))
            ->all());
        $this->assertNull(RaceDay::query()->whereKey($protectedDay->id)->value('encrypted_parameter'));
        $this->assertSame('2026-06-01', $meeting->refresh()->starts_on->format('Y-m-d'));
        $this->assertSame('2026-06-04', $meeting->ends_on->format('Y-m-d'));
    }

    public function test_same_encrypted_parameter_from_adjacent_months_does_not_duplicate_meeting(): void
    {
        $repository = $this->repository();
        $mayItem = $this->scheduleItem('2026-05-31', 1, 'shared-parameter');
        $juneItem = $this->scheduleItem('2026-06-01', 2, 'shared-parameter');

        $firstMeeting = $repository->upsertScheduleItem($mayItem, $this->fetchedAt());
        $firstDayId = RaceDay::query()->where('race_meeting_id', $firstMeeting->id)->value('id');
        $secondMeeting = $repository->upsertScheduleItem($juneItem, $this->fetchedAt());
        $repository->upsertScheduleItem($juneItem, $this->fetchedAt());

        $this->assertSame($firstMeeting->id, $secondMeeting->id);
        $this->assertSame(1, RaceMeeting::query()->count());
        $days = RaceDay::query()->where('race_meeting_id', $firstMeeting->id)->orderBy('race_date')->get();
        $this->assertSame(['20260531', '20260601', '20260602'], $days->map(fn (RaceDay $day): string => $day->race_date->format('Ymd'))->all());
        $this->assertSame($firstDayId, $days->first()->id);
        $this->assertSame(3, $firstMeeting->refresh()->duration_days);
    }

    public function test_metadata_dates_must_be_unique_and_ascending(): void
    {
        $meeting = $this->meeting(['20260601']);

        foreach ([['20260601', '20260601'], ['20260602', '20260601']] as $dates) {
            try {
                $this->repository()->updateMeetingDayParameters($meeting, $this->metadata($dates), $this->fetchedAt());
                $this->fail('ParserException was not thrown.');
            } catch (ParserException) {
                $this->assertSame(1, RaceDay::query()->where('race_meeting_id', $meeting->id)->count());
            }
        }
    }

    private function repository(): RaceRepository
    {
        return $this->app->make(RaceRepository::class);
    }

    /** @param list<string> $dates */
    private function meeting(array $dates): RaceMeeting
    {
        $track = Racetrack::query()->create([
            'source' => 'keirin_jp',
            'external_track_id' => '56',
            'name' => 'synthetic track',
        ]);
        $meeting = RaceMeeting::query()->create([
            'source' => 'keirin_jp',
            'external_meeting_id' => 'meeting-1',
            'racetrack_id' => $track->id,
            'meeting_name' => 'synthetic meeting',
            'starts_on' => DateTimeImmutable::createFromFormat('!Ymd', $dates[0])?->format('Y-m-d'),
            'ends_on' => DateTimeImmutable::createFromFormat('!Ymd', $dates[array_key_last($dates)])?->format('Y-m-d'),
            'duration_days' => count($dates),
            'race_list_url' => 'https://example.test/race-list',
            'encrypted_parameter' => 'meeting-parameter',
        ]);
        foreach ($dates as $index => $date) {
            RaceDay::query()->create([
                'race_meeting_id' => $meeting->id,
                'external_race_day_id' => 'existing-day-'.$date,
                'race_date' => DateTimeImmutable::createFromFormat('!Ymd', $date)?->format('Y-m-d'),
                'day_number' => $index + 1,
                'race_list_url' => 'https://example.test/day/'.$date,
            ]);
        }

        return $meeting;
    }

    /** @param list<string> $dates */
    private function metadata(array $dates): RaceDayMetadataPageDto
    {
        return new RaceDayMetadataPageDto(
            selectedDate: $dates[0],
            trackCode: '56',
            selectedRaceNumber: 1,
            meetingName: 'synthetic meeting',
            trackName: 'synthetic track',
            grade: 'F1',
            days: array_map(
                fn (string $date): RaceDayParameterDto => new RaceDayParameterDto($date, null, 'day-'.$date),
                $dates,
            ),
            races: [],
        );
    }

    private function scheduleItem(string $startsOn, int $durationDays, string $encryptedParameter): RaceScheduleItemDto
    {
        return new RaceScheduleItemDto(
            trackCode: '56',
            trackName: 'synthetic track',
            startsOn: new DateTimeImmutable($startsOn),
            durationDays: $durationDays,
            grade: 'F1',
            raceListUrl: 'https://example.test/race-list',
            encryptedParameter: $encryptedParameter,
            dayKind: null,
        );
    }

    private function relatedRaceData(RaceMeeting $meeting, RaceDay $day): void
    {
        $raceDate = $day->race_date->format('Y-m-d');
        $compactRaceDate = $day->race_date->format('Ymd');
        $player = Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => '000001',
            'registration_number' => '000001',
            'name' => 'synthetic player',
        ]);
        $race = Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => "56:{$compactRaceDate}:01",
            'race_day_id' => $day->id,
            'racetrack_id' => $meeting->racetrack_id,
            'race_date' => $raceDate,
            'race_number' => 1,
        ]);
        $entry = RaceEntry::query()->create([
            'race_id' => $race->id,
            'player_id' => $player->id,
            'external_player_id' => $player->external_player_id,
            'bike_number' => 1,
            'fetched_at' => $this->fetchedAt(),
        ]);
        RaceResult::query()->create([
            'race_id' => $race->id,
            'race_entry_id' => $entry->id,
            'player_id' => $player->id,
            'bike_number' => 1,
            'rank' => 1,
            'result_status' => 'FINISHED',
            'fetched_at' => $this->fetchedAt(),
        ]);
        RacePayout::query()->create([
            'race_id' => $race->id,
            'bet_type_code' => 'WIN',
            'combination' => '1',
            'payout_amount' => 100,
            'fetched_at' => $this->fetchedAt(),
        ]);
    }

    /** @return array<string, int> */
    private function protectedTableCounts(): array
    {
        return [
            'players' => Player::query()->count(),
            'races' => Race::query()->count(),
            'race_entries' => RaceEntry::query()->count(),
            'race_results' => RaceResult::query()->count(),
            'race_payouts' => RacePayout::query()->count(),
        ];
    }

    private function fetchedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-19 12:00:00');
    }
}
