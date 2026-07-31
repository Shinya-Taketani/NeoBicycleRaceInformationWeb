<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Scraping\DTO\PlayerDetailDto;
use App\Domain\Keirin\Scraping\DTO\PlayerSummaryDto;
use App\Domain\Keirin\Scraping\DTO\RetiredPlayerDetailDto;
use App\Domain\Keirin\Scraping\Support\PlayerNameNormalizer;
use App\Models\Player;
use App\Models\PlayerStatSnapshot;
use App\Models\PlayerStatusHistory;
use App\Models\Race;
use App\Models\RaceEntry;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PlayerRepository
{
    /**
     * @return array{player:?Player,linkable_entries:int,would_create:bool,would_update:bool}
     */
    public function retiredDetailLinkPlan(RetiredPlayerDetailDto $dto): array
    {
        $player = Player::query()
            ->where('source', (string) config('keirin.source'))
            ->where('external_player_id', $dto->externalPlayerId)
            ->first();
        $this->assertRegistrationNumberCompatible($player, $dto);
        $entries = $this->unresolvedRetiredEntryQuery($dto->externalPlayerId)->get();
        $this->assertEntryNamesMatch($entries, $dto);

        return [
            'player' => $player,
            'linkable_entries' => $entries->count(),
            'would_create' => $player === null,
            'would_update' => $player !== null,
        ];
    }

    /**
     * @return array{player:Player,linked_entries:int,created:bool}
     */
    public function upsertRetiredDetailAndLinkEntries(RetiredPlayerDetailDto $dto, DateTimeImmutable $fetchedAt): array
    {
        return DB::transaction(function () use ($dto, $fetchedAt): array {
            $player = Player::query()
                ->where('source', (string) config('keirin.source'))
                ->where('external_player_id', $dto->externalPlayerId)
                ->lockForUpdate()
                ->first();
            $this->assertRegistrationNumberCompatible($player, $dto);

            $entries = $this->unresolvedRetiredEntryQuery($dto->externalPlayerId)
                ->lockForUpdate()
                ->get();
            $this->assertEntryNamesMatch($entries, $dto);

            $created = $player === null;
            $player ??= new Player([
                'source' => (string) config('keirin.source'),
                'external_player_id' => $dto->externalPlayerId,
            ]);
            $attributes = [
                'registration_number' => $dto->registrationNumber,
                'name' => $dto->name,
                'status' => 'retired',
                'retired_on' => $dto->retiredOn->format('Y-m-d'),
                'detail_url' => $dto->sourceUrl,
                'last_fetched_at' => $fetchedAt,
            ];
            foreach ([
                'prefecture' => $dto->prefecture,
                'graduation_period' => $dto->graduationPeriod,
                'current_grade' => $dto->grade,
                'source_updated_at' => $dto->sourceUpdatedAt,
            ] as $attribute => $value) {
                if ($value !== null) {
                    $attributes[$attribute] = $value;
                }
            }
            $player->fill($attributes)->save();

            $linkedEntries = RaceEntry::query()
                ->whereKey($entries->modelKeys())
                ->update([
                    'player_id' => $player->id,
                    'updated_at' => $fetchedAt,
                ]);

            return [
                'player' => $player,
                'linked_entries' => $linkedEntries,
                'created' => $created,
            ];
        });
    }

    public function upsertSummary(PlayerSummaryDto $dto, ?DateTimeImmutable $sourceUpdatedAt, DateTimeImmutable $fetchedAt): Player
    {
        return DB::transaction(function () use ($dto, $sourceUpdatedAt, $fetchedAt): Player {
            return Player::query()->updateOrCreate(
                [
                    'source' => (string) config('keirin.source'),
                    'external_player_id' => $dto->externalPlayerId,
                ],
                [
                    'registration_number' => $dto->externalPlayerId,
                    'name' => $dto->name,
                    'name_kana' => $dto->nameKana,
                    'gender' => $dto->gender,
                    'current_grade' => $dto->grade,
                    'graduation_period' => $dto->graduationPeriod,
                    'prefecture' => $dto->prefecture,
                    'district' => $dto->district,
                    'riding_style' => $dto->ridingStyle,
                    'home_bank' => $dto->homeBank,
                    'status' => 'active',
                    'detail_url' => $dto->detailUrl,
                    'source_updated_at' => $sourceUpdatedAt,
                    'last_fetched_at' => $fetchedAt,
                ],
            );
        });
    }

    public function upsertDetail(PlayerDetailDto $dto, DateTimeImmutable $fetchedAt): Player
    {
        return DB::transaction(function () use ($dto, $fetchedAt): Player {
            $player = Player::query()->updateOrCreate(
                [
                    'source' => (string) config('keirin.source'),
                    'external_player_id' => $dto->externalPlayerId,
                ],
                [
                    'registration_number' => $dto->registrationNumber,
                    'name' => $dto->name,
                    'name_kana' => $dto->nameKana,
                    'birth_date' => $dto->birthDate?->format('Y-m-d'),
                    'gender' => $dto->gender,
                    'current_grade' => $dto->grade,
                    'graduation_period' => $dto->graduationPeriod,
                    'prefecture' => $dto->prefecture,
                    'riding_style' => $dto->ridingStyle,
                    'status' => $dto->gender === 'female' ? 'unsupported_category' : 'active',
                    'detail_url' => $dto->sourceUrl,
                    'source_updated_at' => $dto->sourceUpdatedAt,
                    'last_fetched_at' => $fetchedAt,
                ],
            );

            foreach ($dto->gradeHistories as $history) {
                PlayerStatusHistory::query()->updateOrCreate(
                    [
                        'player_id' => $player->id,
                        'grade' => $history->grade,
                        'grade_assigned_on' => $history->assignedOn?->format('Y-m-d'),
                    ],
                    [
                        'status' => $dto->gender === 'female' ? 'unsupported_category' : 'active',
                        'source_url' => $dto->sourceUrl,
                        'fetched_at' => $fetchedAt,
                    ],
                );
            }

            if ($dto->recentStats !== null) {
                $sourceHash = hash('sha256', json_encode([
                    'basis_date' => $dto->sourceUpdatedAt?->format('Y-m-d'),
                    'race_score' => $dto->recentStats->raceScore,
                    'win_rate' => $dto->recentStats->winRate,
                    'quinella_rate' => $dto->recentStats->quinellaRate,
                    'trio_rate' => $dto->recentStats->trioRate,
                    'back_count' => $dto->recentStats->backCount,
                    'home_count' => $dto->recentStats->homeCount,
                    'start_count' => $dto->recentStats->startCount,
                ], JSON_THROW_ON_ERROR));

                $snapshot = PlayerStatSnapshot::query()->firstOrNew([
                    'player_id' => $player->id,
                    'source_hash' => $sourceHash,
                ]);

                $snapshot->fill([
                    'basis_date' => $dto->sourceUpdatedAt?->format('Y-m-d'),
                    'race_score' => $dto->recentStats->raceScore,
                    'win_rate' => $dto->recentStats->winRate,
                    'quinella_rate' => $dto->recentStats->quinellaRate,
                    'trio_rate' => $dto->recentStats->trioRate,
                    'back_count' => $dto->recentStats->backCount,
                    'home_count' => $dto->recentStats->homeCount,
                    'start_count' => $dto->recentStats->startCount,
                    'source_url' => $dto->sourceUrl,
                    'first_fetched_at' => $snapshot->exists ? $snapshot->first_fetched_at : $fetchedAt,
                    'last_fetched_at' => $fetchedAt,
                ])->save();
            }

            return $player;
        });
    }

    /**
     * @return Builder<RaceEntry>
     */
    private function unresolvedRetiredEntryQuery(string $externalPlayerId): Builder
    {
        return RaceEntry::query()
            ->whereIn(
                'race_id',
                Race::query()
                    ->select('id')
                    ->where('source', (string) config('keirin.source')),
            )
            ->whereNull('player_id')
            ->where('external_player_id', $externalPlayerId);
    }

    private function assertRegistrationNumberCompatible(?Player $player, RetiredPlayerDetailDto $dto): void
    {
        if ($player !== null
            && $player->registration_number !== null
            && $player->registration_number !== $dto->registrationNumber) {
            throw new RuntimeException(
                "Existing player registration number {$player->registration_number} did not match {$dto->registrationNumber}.",
            );
        }
    }

    private function assertEntryNamesMatch($entries, RetiredPlayerDetailDto $dto): void
    {
        $expectedName = PlayerNameNormalizer::comparisonKey($dto->name);
        foreach ($entries as $entry) {
            $entryName = PlayerNameNormalizer::comparisonKey($entry->player_name);
            if ($entryName !== null && $entryName !== $expectedName) {
                throw new RuntimeException(
                    "Race entry {$entry->id} name did not match retired player {$dto->externalPlayerId}.",
                );
            }
        }
    }
}
