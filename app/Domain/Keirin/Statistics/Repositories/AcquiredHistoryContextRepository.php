<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\Support\RaceStageNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class AcquiredHistoryContextRepository
{
    public function __construct(private readonly RaceStageNormalizer $stages) {}

    public function birthDate(?int $playerId): ?DateTimeImmutable
    {
        $date = $playerId === null ? null : DB::table('players')->where('id', $playerId)->value('birth_date');

        return $date === null ? null : new DateTimeImmutable($date, new DateTimeZone('Asia/Tokyo'));
    }

    public function matchedSchoolProfile(int $raceEntryId, ?array $profile): ?array
    {
        if ($profile === null) {
            return null;
        }
        $id = DB::table('race_entries')->where('id', $raceEntryId)->value('external_player_id');
        if (($profile['external_player_id'] ?? null) !== $id) {
            return null;
        }
        if (($profile['version'] ?? null) !== 'school-profile-projection-v1' || ($profile['prediction_use'] ?? null) !== 'NOT_AUTHORIZED'
            || ($profile['historical_as_of_available'] ?? null) !== false || ! is_int($profile['source']['fetch_log_id'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $profile['source']['sha256'] ?? '') !== 1) {
            throw new \InvalidArgumentException('Invalid sealed school projection.');
        }
        if (($profile['debut_date'] ?? null) !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $profile['debut_date'], new DateTimeZone('Asia/Tokyo'));
            if ($date === false || $date->format('Y-m-d') !== $profile['debut_date']) {
                throw new \InvalidArgumentException('Invalid saved debut date.');
            }
        }

        return $profile;
    }

    public function stages(array $histories): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map(fn ($e) => $e->raceId, $histories))), 250) as $ids) {
            foreach (DB::table('races')->whereIn('id', $ids)->whereBetween('race_date', ['2022-01-01', '2025-12-31'])
                ->select(['id', 'race_type'])->orderBy('id')->get() as $row) {
                $result[(int) $row->id] = ['race_type_raw' => $row->race_type,
                    'stage' => $this->stages->normalize($row->race_type)->value, 'normalizer_version' => RaceStageNormalizer::VERSION];
            }
        }

        return $result;
    }
}
