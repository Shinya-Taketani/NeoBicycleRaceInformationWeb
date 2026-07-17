<?php

declare(strict_types=1);

return [
    'source' => 'keirin_jp',
    'base_url' => env('KEIRIN_BASE_URL', 'https://keirin.jp'),
    'user_agent' => env('KEIRIN_USER_AGENT', 'NeoBicycleRaceInformationWeb/0.1 (+https://localhost; contact: admin@example.local)'),
    'connect_timeout_seconds' => (float) env('KEIRIN_CONNECT_TIMEOUT', 5),
    'timeout_seconds' => (float) env('KEIRIN_REQUEST_TIMEOUT', 20),
    'retry_times' => (int) env('KEIRIN_RETRY_TIMES', 2),
    'retry_base_sleep_ms' => (int) env('KEIRIN_RETRY_BASE_SLEEP_MS', 500),
    'sleep_ms' => (int) env('KEIRIN_SLEEP_MS', 1000),
    'parser_version' => env('KEIRIN_PARSER_VERSION', '2026-07-18-initial'),
    'raw_disk' => env('KEIRIN_RAW_DISK', 'local'),
    'raw_root' => env('KEIRIN_RAW_ROOT', 'private/scraping/raw'),
    'raw_import_root' => env('KEIRIN_RAW_IMPORT_ROOT', 'private/scraping/raw-import'),
    'max_player_pages_per_grade' => (int) env('KEIRIN_MAX_PLAYER_PAGES_PER_GRADE', 100),
    'routes' => [
        'player_search_result' => '/sp/racersearchresult',
        'player_detail_pc' => '/pc/racerprofile',
        'race_schedule' => '/pc/raceschedule',
    ],
    'male_grade_codes' => [
        '15', // S級S班
        '11', // S級1班
        '12', // S級2班
        '21', // A級1班
        '22', // A級2班
        '23', // A級3班
    ],
];
