<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Domain\Keirin\Statistics\StartObservation\Contract as LedgerContract;

final class Contract
{
    public const VERSION = 'STAT36-START-COUNT-v1';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/stat36-start-count-01';

    public const YEARS = [2022, 2023, 2024, 2025];

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'ledger' => LedgerContract::LEDGER,
            'ledger_seal' => LedgerContract::LEDGER_SEAL, 'years' => self::YEARS,
            'field' => 'displayed_start_count', 'pointer' => 'PJ0315.sensyuTypeInfo[].stTori',
            'numeric_rule' => 'INT_OR_ASCII_DIGITS_STRING_0_TO_9999_TECHNICAL_RANGE_ONLY',
            'missing' => ['MISSING', 'NULL', 'EMPTY_STRING', 'MISSING_SYMBOL'],
            'invalid' => ['INVALID_FORMAT', 'OUT_OF_RANGE'],
            'identity' => 'LEDGER_PC0201_PJ0315_RACE_AND_BIKE_EXTERNAL_ID',
            'versions' => 'ALL_FETCHES_NO_LATEST_SELECTION',
            'sample' => 'FIRST_TWO_LEDGER_RACES_PER_YEAR_FIRST_FETCH_ID_ASC',
            'aggregation_period' => null, 'statistical_as_of' => null, 'correction_as_of' => null,
            'timing_status' => 'UNKNOWN_NO_S_SPECIFIC_PERIOD_EVIDENCE',
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'points' => null,
            'export' => 'DEDICATED_READ_ONLY_REPEATABLE_READ_RACES_AND_FETCH_METADATA_ONLY',
            'build_reproduce' => 'OFFLINE_NO_DB_NO_HTTP', '2026_races' => 'FORBIDDEN'];
    }
}
