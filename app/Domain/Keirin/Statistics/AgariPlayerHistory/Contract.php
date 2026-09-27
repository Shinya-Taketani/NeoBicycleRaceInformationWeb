<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariPlayerHistory;

use App\Domain\Keirin\Statistics\AgariRaceRelative\Contract as Relative;

final class Contract
{
    public const VERSION = 'STAT35-PLAYER-HISTORY-v1';

    public const DISCLOSURE = [...Relative::DISCLOSURE,
        'history_time_basis' => 'EVENT_DATE_BACKFILLED_FINAL_RESULTS'];

    public const WINDOWS = [3, 6, 12];

    public const FILES = ['player-meetings.jsonl', 'entry-history.jsonl', 'summary.json', 'summary.csv', 'manifest.json', 'COMPLETE.json'];

    public const INPUT_SEAL = ['bytes' => 1264636014, 'sha256' => 'eefd90026c2144841aa5a169882a8d98efe699ba0d83aae087985bbec96dc142'];

    public const RESULT_SEAL = ['bytes' => 993001624, 'sha256' => '67a52218421afc85c097a796d4e00eeb75f49f698a20e095cc0472980fbd3d7b'];
}
