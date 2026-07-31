<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\DTO\RetiredPlayerDetailDto;
use App\Models\BatchRun;
use App\Models\BatchRunItem;
use App\Models\Player;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\ScrapingFetchLog;
use App\Repositories\PlayerRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BackfillRetiredPlayersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_backfill_creates_retired_player_links_entries_and_is_idempotent(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $entries = $this->createEntries('012345', '合成　太郎', 3);
        Http::fake(['keirin.jp/*' => Http::response(
            $this->profileHtml('012345', '合成　太郎'),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);
        $arguments = ['--external-player-id' => '012345', '--sleep-ms' => '0'];

        $this->artisan('keirin:players:backfill-retired', $arguments)
            ->expectsOutputToContain('name=合成 太郎')
            ->expectsOutputToContain('linked_entries=3')
            ->assertExitCode(0);

        $player = Player::query()->where('external_player_id', '012345')->firstOrFail();
        $this->assertSame('012345', $player->registration_number);
        $this->assertSame('retired', $player->status);
        $this->assertSame('2025-01-31', $player->retired_on?->format('Y-m-d'));
        $this->assertSame('71', $player->graduation_period);
        $this->assertSame('A3', $player->current_grade);
        $this->assertSame(
            [(int) $player->id],
            RaceEntry::query()->whereIn('id', $entries->modelKeys())->distinct()->pluck('player_id')->all(),
        );
        $raceIds = Race::query()->orderBy('id')->pluck('id')->all();
        $entryIds = RaceEntry::query()->orderBy('id')->pluck('id')->all();

        $this->artisan('keirin:players:backfill-retired', $arguments)
            ->expectsOutputToContain('skip_reason=PLAYER_ALREADY_LINKED')
            ->expectsOutputToContain('linked_entries=0')
            ->assertExitCode(0);

        $this->assertSame((int) $player->id, (int) Player::query()->where('external_player_id', '012345')->value('id'));
        $this->assertSame(1, Player::query()->where('external_player_id', '012345')->count());
        $this->assertSame($raceIds, Race::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($entryIds, RaceEntry::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, BatchRun::query()->where('status', 'SUCCEEDED')->count());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
        $this->assertSame(0, BatchRunItem::query()->where('status', 'FAILED')->count());
        $this->assertSame(1, ScrapingFetchLog::query()->count());
        $this->assertNotEmpty(ScrapingFetchLog::query()->value('raw_file_path'));
    }

    public function test_dry_run_fetches_and_validates_without_updating_players_or_entries(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $entries = $this->createEntries('012345', " 合成\t太郎 ", 3);
        Http::fake(['keirin.jp/*' => Http::response(
            $this->profileHtml('012345', '合成　太郎'),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $this->artisan('keirin:players:backfill-retired', [
            '--external-player-id' => '012345',
            '--sleep-ms' => '0',
            '--dry-run' => true,
        ])->expectsOutputToContain('would_create_player=1')
            ->expectsOutputToContain('would_update_player=0')
            ->expectsOutputToContain('would_link_entries=3')
            ->expectsOutputToContain('players=0 linked_entries=0')
            ->assertExitCode(0);

        $this->assertSame(0, Player::query()->count());
        $this->assertSame(
            3,
            RaceEntry::query()->whereIn('id', $entries->modelKeys())->whereNull('player_id')->count(),
        );
        $this->assertSame(1, ScrapingFetchLog::query()->count());
        $this->assertDatabaseHas('batch_run_items', [
            'item_type' => 'RETIRED_PLAYER_DETAIL',
            'status' => 'SUCCEEDED',
        ]);
    }

    public function test_period_mode_deduplicates_orders_and_limits_candidates(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->createEntries('000003', '選手　三', 2, '2023-06-01');
        $otherSourceEntries = $this->createEntries('000003', '選手　三', 10, '2023-06-01', 'other_source');
        $this->createEntries('000002', '選手　二', 3, '2023-06-02');
        $this->createEntries('000001', '選手　一', 3, '2023-06-03');
        $this->createEntries('ABCDEF', '対象外', 4, '2023-06-04');
        $requested = [];
        Http::fake(function (Request $request) use (&$requested) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $id = (string) $query['snum'];
            $requested[] = $id;
            $names = ['000001' => '選手　一', '000002' => '選手　二', '000003' => '選手　三'];

            return Http::response($this->profileHtml($id, $names[$id]), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        });

        $this->artisan('keirin:players:backfill-retired', [
            '--from' => '2023-01-01',
            '--to' => '2023-12-31',
            '--limit' => '2',
            '--sleep-ms' => '0',
        ])->assertExitCode(0);

        $this->assertSame(['000001', '000002'], $requested);
        $this->assertSame(2, Player::query()->count());
        $this->assertSame(6, RaceEntry::query()->whereNotNull('player_id')->count());
        $this->assertSame(1, RaceEntry::query()->where('external_player_id', '000003')->whereNull('player_id')->count() > 0 ? 1 : 0);
        $this->assertSame(
            10,
            RaceEntry::query()->whereIn('id', $otherSourceEntries->modelKeys())->whereNull('player_id')->count(),
        );
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_period_mode_continues_after_one_player_fails(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->createEntries('000001', '選手　一', 3, '2023-06-01');
        $this->createEntries('000002', '選手　二', 2, '2023-06-02');
        $requested = [];
        Http::fake(function (Request $request) use (&$requested) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $id = (string) $query['snum'];
            $requested[] = $id;

            return Http::response(
                $id === '000001'
                    ? $this->profileHtml('999999', '選手　一')
                    : $this->profileHtml('000002', '選手　二'),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        });

        $this->artisan('keirin:players:backfill-retired', [
            '--from' => '2023-01-01',
            '--to' => '2023-12-31',
            '--sleep-ms' => '0',
        ])->assertExitCode(1);

        $this->assertSame(['000001', '000002'], $requested);
        $this->assertSame(0, Player::query()->where('external_player_id', '000001')->count());
        $this->assertSame(1, Player::query()->where('external_player_id', '000002')->count());
        $this->assertSame(1, BatchRunItem::query()->where('status', 'FAILED')->count());
        $this->assertSame(1, BatchRunItem::query()->where('status', 'SUCCEEDED')->count());
        $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_active_profile_is_skipped_without_creating_a_player(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->createEntries('012345', '合成　太郎', 1);
        $activeProfile = str_replace(
            '<p class="retired">本選手は、2025年01月31日に引退しました。</p>',
            '',
            $this->profileHtml('012345', '合成　太郎'),
        );
        Http::fake(['keirin.jp/*' => Http::response(
            $activeProfile,
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $this->artisan('keirin:players:backfill-retired', [
            '--external-player-id' => '012345',
            '--sleep-ms' => '0',
        ])->expectsOutputToContain('skip_reason=PROFILE_NOT_RETIRED')
            ->assertExitCode(0);

        $this->assertSame(0, Player::query()->count());
        $this->assertSame(1, RaceEntry::query()->whereNull('player_id')->count());
        $this->assertDatabaseHas('batch_run_items', [
            'status' => 'SKIPPED',
            'skip_reason' => 'PROFILE_NOT_RETIRED',
        ]);
    }

    public function test_actual_active_profile_is_skipped_without_updating_entries(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $entry = $this->createEntries('014934', '現役選手', 1)->firstOrFail();
        Http::fake(['keirin.jp/*' => Http::response(
            (string) file_get_contents(base_path('tests/Fixtures/Keirin/actual/player_detail_014934.html')),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $this->artisan('keirin:players:backfill-retired', [
            '--external-player-id' => '014934',
            '--sleep-ms' => '0',
        ])->expectsOutputToContain('skip_reason=PROFILE_NOT_RETIRED')
            ->expectsOutputToContain('success=0 skipped=1 failed=0')
            ->assertExitCode(0);

        $this->assertSame(0, Player::query()->count());
        $this->assertNull($entry->refresh()->player_id);
        $this->assertDatabaseHas('batch_run_items', [
            'item_type' => 'RETIRED_PLAYER_DETAIL',
            'status' => 'SKIPPED',
            'skip_reason' => 'PROFILE_NOT_RETIRED',
        ]);
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_l1_retired_profile_is_skipped_before_link_planning(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $entry = $this->createEntries('012345', 'リンクしてはいけない別名', 1)->firstOrFail();
        Http::fake(['keirin.jp/*' => Http::response(
            str_replace('A級3班', 'L級1班', $this->profileHtml('012345', '合成　太郎')),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $this->artisan('keirin:players:backfill-retired', [
            '--external-player-id' => '012345',
            '--sleep-ms' => '0',
        ])->expectsOutputToContain('skip_reason=SKIPPED_UNSUPPORTED_CATEGORY')
            ->expectsOutputToContain('success=0 skipped=1 failed=0')
            ->assertExitCode(0);

        $this->assertSame(0, Player::query()->count());
        $this->assertNull($entry->refresh()->player_id);
        $item = BatchRunItem::query()->sole();
        $this->assertSame('SKIPPED_UNSUPPORTED_CATEGORY', $item->status);
        $this->assertSame('SKIPPED_UNSUPPORTED_CATEGORY', $item->skip_reason);
        $this->assertSame('L1', $item->metadata['grade']);
        $this->assertNull($item->metadata['existing_gender']);
        $run = BatchRun::query()->sole();
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame(0, $run->failure_count);
    }

    public function test_existing_female_player_is_skipped_without_being_retired_or_linked(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $player = Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => '012345',
            'registration_number' => '012345',
            'name' => '既存女子選手',
            'gender' => 'female',
            'status' => 'unsupported_category',
            'retired_on' => null,
        ]);
        $entry = $this->createEntries('012345', 'リンクしてはいけない別名', 1)->firstOrFail();
        Http::fake(['keirin.jp/*' => Http::response(
            $this->profileHtml('012345', '合成　太郎'),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $this->artisan('keirin:players:backfill-retired', [
            '--external-player-id' => '012345',
            '--sleep-ms' => '0',
        ])->expectsOutputToContain('skip_reason=SKIPPED_UNSUPPORTED_CATEGORY')
            ->assertExitCode(0);

        $player->refresh();
        $this->assertSame('unsupported_category', $player->status);
        $this->assertNull($player->retired_on);
        $this->assertNull($entry->refresh()->player_id);
        $item = BatchRunItem::query()->sole();
        $this->assertSame('SKIPPED_UNSUPPORTED_CATEGORY', $item->status);
        $this->assertSame('female', $item->metadata['existing_gender']);
        $this->assertSame('A3', $item->metadata['grade']);
        $this->assertSame(0, BatchRun::query()->sole()->failure_count);
    }

    public function test_linking_and_dry_run_counts_are_limited_to_keirin_source_entries(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $keirinEntries = $this->createEntries('012345', '合成　太郎', 2);
        $otherEntries = $this->createEntries('012345', '合成　太郎', 4, '2023-06-02', 'other_source');
        Http::fake(['keirin.jp/*' => Http::response(
            $this->profileHtml('012345', '合成　太郎'),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);
        $arguments = ['--external-player-id' => '012345', '--sleep-ms' => '0'];

        $this->artisan('keirin:players:backfill-retired', [...$arguments, '--dry-run' => true])
            ->expectsOutputToContain('would_link_entries=2')
            ->assertExitCode(0);

        $this->assertSame(0, Player::query()->count());
        $this->assertSame(2, RaceEntry::query()->whereIn('id', $keirinEntries->modelKeys())->whereNull('player_id')->count());
        $this->assertSame(4, RaceEntry::query()->whereIn('id', $otherEntries->modelKeys())->whereNull('player_id')->count());

        $this->artisan('keirin:players:backfill-retired', $arguments)
            ->expectsOutputToContain('linked_entries=2')
            ->assertExitCode(0);

        $player = Player::query()->where('external_player_id', '012345')->sole();
        $this->assertSame(
            2,
            RaceEntry::query()->whereIn('id', $keirinEntries->modelKeys())->where('player_id', $player->id)->count(),
        );
        $this->assertSame(
            4,
            RaceEntry::query()->whereIn('id', $otherEntries->modelKeys())->whereNull('player_id')->count(),
        );
    }

    public function test_individual_mode_ignores_entries_from_other_sources(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $otherEntries = $this->createEntries('012345', '合成　太郎', 3, '2023-06-01', 'other_source');
        Http::fake();

        $this->artisan('keirin:players:backfill-retired', [
            '--external-player-id' => '012345',
            '--sleep-ms' => '0',
        ])->expectsOutputToContain('skip_reason=NO_UNRESOLVED_ENTRIES')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, Player::query()->count());
        $this->assertSame(
            3,
            RaceEntry::query()->whereIn('id', $otherEntries->modelKeys())->whereNull('player_id')->count(),
        );
    }

    public function test_invalid_command_options_are_rejected_before_starting_a_batch(): void
    {
        $cases = [
            [],
            ['--external-player-id' => '012345', '--from' => '2023-01-01', '--to' => '2023-12-31'],
            ['--from' => '2023-01-01'],
            ['--to' => '2023-12-31'],
            ['--from' => '2023-12-31', '--to' => '2023-01-01'],
            ['--from' => '2023-01-01', '--to' => '2023-12-31', '--limit' => '0'],
            ['--external-player-id' => '012345', '--sleep-ms' => '-1'],
            ['--external-player-id' => '12345'],
        ];

        foreach ($cases as $options) {
            $this->artisan('keirin:players:backfill-retired', $options)->assertExitCode(1);
        }

        $this->assertSame(0, BatchRun::query()->count());
    }

    public function test_repository_preserves_existing_attributes_and_allows_whitespace_only_name_differences(): void
    {
        $player = Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => '012345',
            'registration_number' => null,
            'name' => '旧氏名',
            'name_kana' => 'キュウシメイ',
            'birth_date' => '1970-01-01',
            'gender' => 'male',
            'prefecture' => '既存県',
            'graduation_period' => '70',
            'current_grade' => 'A2',
            'district' => '南関東',
            'home_bank' => '既存場',
            'riding_style' => '追',
        ]);
        $entries = $this->createEntries('012345', " 合成\n\t太郎 ", 2);
        $linkedMaster = Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => '065432',
            'registration_number' => '065432',
            'name' => 'リンク済み選手',
        ]);
        $alreadyLinked = $this->createEntries('012345', '別人', 1)->firstOrFail();
        $alreadyLinked->forceFill(['player_id' => $linkedMaster->id])->save();
        $differentExternalId = $this->createEntries('099999', '別選手', 1)->firstOrFail();
        $dto = $this->dto(prefecture: null, graduationPeriod: null, grade: null, sourceUpdatedAt: null);

        $result = app(PlayerRepository::class)->upsertRetiredDetailAndLinkEntries(
            $dto,
            new DateTimeImmutable('2026-07-31 10:00:00'),
        );

        $this->assertFalse($result['created']);
        $this->assertSame(2, $result['linked_entries']);
        $player->refresh();
        $this->assertSame('012345', $player->registration_number);
        $this->assertSame('retired', $player->status);
        $this->assertSame('既存県', $player->prefecture);
        $this->assertSame('70', $player->graduation_period);
        $this->assertSame('A2', $player->current_grade);
        $this->assertSame('キュウシメイ', $player->name_kana);
        $this->assertSame('1970-01-01', $player->birth_date?->format('Y-m-d'));
        $this->assertSame('male', $player->gender);
        $this->assertSame('南関東', $player->district);
        $this->assertSame('既存場', $player->home_bank);
        $this->assertSame('追', $player->riding_style);
        $this->assertSame(
            2,
            RaceEntry::query()->whereIn('id', $entries->modelKeys())->where('player_id', $player->id)->count(),
        );
        $this->assertSame((int) $linkedMaster->id, (int) $alreadyLinked->refresh()->player_id);
        $this->assertNull($differentExternalId->refresh()->player_id);
    }

    public function test_repository_rolls_back_registration_conflicts_and_name_mismatches(): void
    {
        $player = Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => '012345',
            'registration_number' => '999999',
            'name' => '既存選手',
        ]);
        $entry = $this->createEntries('012345', '合成　太郎', 1)->firstOrFail();

        try {
            app(PlayerRepository::class)->upsertRetiredDetailAndLinkEntries(
                $this->dto(),
                new DateTimeImmutable('2026-07-31 10:00:00'),
            );
            $this->fail('Registration conflict was not rejected.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('既存選手', $player->refresh()->name);
        $this->assertNull($entry->refresh()->player_id);

        $player->delete();
        $entry->forceFill(['player_name' => '別人'])->save();
        try {
            app(PlayerRepository::class)->upsertRetiredDetailAndLinkEntries(
                $this->dto(),
                new DateTimeImmutable('2026-07-31 10:00:00'),
            );
            $this->fail('Name mismatch was not rejected.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, Player::query()->where('external_player_id', '012345')->count());
        $this->assertNull($entry->refresh()->player_id);
    }

    private function createEntries(
        string $externalPlayerId,
        string $name,
        int $count,
        string $raceDate = '2023-06-01',
        string $source = 'keirin_jp',
    ) {
        $race = Race::query()->create([
            'source' => $source,
            'external_race_id' => "{$externalPlayerId}:{$raceDate}:".Race::query()->count(),
            'race_date' => $raceDate,
            'race_number' => 1,
        ]);
        foreach (range(1, $count) as $bikeNumber) {
            RaceEntry::query()->create([
                'race_id' => $race->id,
                'external_player_id' => $externalPlayerId,
                'player_name' => $name,
                'bike_number' => $bikeNumber,
                'fetched_at' => new DateTimeImmutable('2023-06-01 10:00:00'),
            ]);
        }

        return RaceEntry::query()->where('race_id', $race->id)->orderBy('bike_number')->get();
    }

    private function profileHtml(string $externalPlayerId, string $name): string
    {
        return str_replace(
            ['012345', '合成　太郎'],
            [$externalPlayerId, $name],
            (string) file_get_contents(base_path('tests/Fixtures/Keirin/synthetic/player-detail-retired.html')),
        );
    }

    private function dto(
        ?string $prefecture = '合成県',
        ?string $graduationPeriod = '71',
        ?string $grade = 'A3',
        ?DateTimeImmutable $sourceUpdatedAt = new DateTimeImmutable('2025-07-01 02:34:00'),
    ): RetiredPlayerDetailDto {
        return new RetiredPlayerDetailDto(
            externalPlayerId: '012345',
            registrationNumber: '012345',
            name: '合成　太郎',
            prefecture: $prefecture,
            age: 53,
            graduationPeriod: $graduationPeriod,
            grade: $grade,
            retiredOn: new DateTimeImmutable('2025-01-31'),
            sourceUpdatedAt: $sourceUpdatedAt,
            sourceUrl: 'https://example.test/pc/racerprofile?snum=012345',
        );
    }
}
