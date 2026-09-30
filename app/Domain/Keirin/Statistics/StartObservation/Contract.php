<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartObservation;

use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract as Audit;

final class Contract
{
    public const VERSION = 'STAT36-OBSERVATION-v2-DISPLAY-ONLY';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/stat36-observation-01';

    public const LEDGER = '/home/shinya/neo-keirin-artifacts/stat35-data-readiness-audit-01-20260921-01/stat35-agari-readiness-2022-2025-pr64-review-fix-01';

    public const LEDGER_SEAL = ['bytes' => 8875, 'sha256' => 'bd7ea209724bb1848ad4a44b1c9930bb0a5bb9c23806b0183eb0c6c07f9710a1'];

    public const YEARS = [2022, 2023, 2024, 2025];

    public const FIELDS = ['BH', 'inLineJyuni', 'kojinStateItemSubData'];

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'purpose' => 'OFFLINE_SAVED_RESULT_DISPLAY_OBSERVATIONS_ONLY',
            'years' => self::YEARS, 'ledger' => self::LEDGER, 'ledger_seal' => self::LEDGER_SEAL,
            'ledger_contract' => Audit::VERSION, 'headers' => Audit::HEADERS,
            'fields' => self::FIELDS, 'display_marker_path' => 'PJ0326.tyakujyunItemSubData[].kojinStateItemSubData[].kojinState',
            'display_marker_literal' => 'S', 'literal_policy' => 'EXACT_STRING_NO_ALIASES_NO_SUBSTRING',
            'display_signature_version' => DisplaySignature::VERSION,
            'display_signature_policy' => 'SORT_OBJECT_KEYS_ONLY_KEEP_LIST_ORDER_PRESENCE_TYPES_AND_CONTENT',
            'definition_status' => 'UNKNOWN_POSITION_DEFINITION',
            'start_acquired' => null, 'confirmed_start_absence_rule' => null,
            'display_evidence' => 'Stored result header is 個人状況; tbody is empty; rendering JavaScript is external and not captured by the accepted ledger.',
            'evidence_imports' => [12547, 12814, 38077, 38085, 63692, 89246, 114816, 114825],
            'evidence_selection' => 'FIRST_TWO_IMPORTS_PER_YEAR_IN_SEALED_LEDGER_ORDER_WITHOUT_OUTCOME_SELECTION',
            'non_inference' => ['BH_IS_NOT_START', 'inLineJyuni_IS_NOT_INITIAL_POSITION', 'EMPTY_IS_NOT_START_FALSE'],
            'initial_position_status' => 'MISSING_INITIAL_POSITION', 'historical_as_of_available' => false,
            'source_time_meaning' => 'SYSTEM_FETCH_TIME_NOT_OFFICIAL_PUBLICATION_OR_CORRECTION_TIME',
            'processing_time_location' => 'execution.json (not used as source time)',
            'prediction_use' => 'NOT_AUTHORIZED', 'points' => null,
            'revision_policy' => 'KEEP_ALL_IMPORTS_NO_LATEST_SELECTION', 'db_http' => 'FORBIDDEN', '2026_race_access' => 'FORBIDDEN'];
    }
}
