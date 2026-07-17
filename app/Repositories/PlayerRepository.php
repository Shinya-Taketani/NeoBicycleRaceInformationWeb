<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Scraping\DTO\PlayerDetailDto;
use App\Domain\Keirin\Scraping\DTO\PlayerSummaryDto;
use App\Models\Player;
use App\Models\PlayerStatSnapshot;
use App\Models\PlayerStatusHistory;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class PlayerRepository
{
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
}
