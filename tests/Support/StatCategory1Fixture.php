<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Statistics\DTO\Stat01BuildOptionsDto;
use App\Domain\Keirin\Statistics\Services\Stat01FeatureBuildService;
use Illuminate\Support\Facades\DB;

final class StatCategory1Fixture
{
    // Synthetic records only; this fixture never reads production data or Raw.
    public static function race(int $count = 7, string $date = '2024-01-02'): int
    {
        $token = bin2hex(random_bytes(8));
        $race = (int) DB::table('races')->insertGetId([
            'source' => 'category1-synthetic', 'external_race_id' => $token,
            'race_date' => $date, 'race_number' => 1, 'entrant_count' => $count,
            'race_type' => "\u{FF21}\u{7D1A}\u{4E88}\u{9078}",
            'scheduled_start_at' => $date.' 12:00:00', 'sales_close_at' => $date.' 11:55:00',
            'result_status' => 'UNAVAILABLE', 'result_available' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($bike = 1; $bike <= $count; $bike++) {
            $player = (int) DB::table('players')->insertGetId([
                'source' => 'category1-synthetic', 'external_player_id' => $token.'-'.$bike,
                'name' => 'Synthetic player '.$bike, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('race_entries')->insert([
                'race_id' => $race, 'player_id' => $player, 'external_player_id' => $token.'-'.$bike,
                'bike_number' => $bike, 'frame_number' => $bike, 'grade' => 'A1', 'race_score' => 90 - $bike,
                'fetched_at' => $date.' 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $race;
    }

    public static function stat01(int $race): int
    {
        return (int) app(Stat01FeatureBuildService::class)
            ->build(new Stat01BuildOptionsDto(null, null, $race, 100, false))->runId;
    }
}
