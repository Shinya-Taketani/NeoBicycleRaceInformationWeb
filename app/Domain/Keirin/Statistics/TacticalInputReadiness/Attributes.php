<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\TacticalInputReadiness;

use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;

final class Attributes
{
    public static function classify(array $target, array $record): array
    {
        C1::keys($record, Contract::RECORD_KEYS);
        $identity = $record['entry_id'] === $target['entry_id'] && $record['race_id'] === $target['race_id']
            && $record['bike'] === $target['bike'] && $record['race_date'] === $target['race_date']
            && $record['player_id'] === $target['player_id'] && $target['external_player_id'] !== null
            && $record['external_player_id'] === $target['external_player_id'];
        $style = self::value($record['riding_style']);
        $line = self::value($record['line_text']);
        $known = $style === 'VALUE' && array_key_exists($record['riding_style'], Contract::STYLES);

        return ['entry_id' => $target['entry_id'], 'bike' => $target['bike'],
            'external_player_id' => $target['external_player_id'], 'identity' => $identity ? 'MATCH' : 'IDENTITY_MISMATCH',
            'riding_style' => ['raw' => $record['riding_style'], 'value_status' => $style,
                'normalized' => $identity && $known ? Contract::STYLES[$record['riding_style']] : null,
                'semantic_status' => $known ? 'KNOWN_SAVED_CATEGORY' : ($style === 'VALUE' ? 'UNSUPPORTED_FORMAT' : $style),
                'source' => ['column' => 'race_entries.riding_style', 'writers' => ['JSJ017.sInfo.kyaku', 'PJ0315.sensyuTypeInfo.kyakusitu']],
                'timing_status' => 'UNKNOWN_SOURCE_TIMING', 'observed_at' => null],
            'line' => ['raw' => $record['line_text'], 'value_status' => $line, 'role' => null,
                'semantic_status' => 'UNCONFIRMED_SOURCE_NO_ROLE_INFERENCE',
                'source' => ['column' => 'race_entries.line_text', 'writer' => 'NOT_FOUND_IN_CURRENT_CODE'],
                'timing_status' => 'UNKNOWN_SOURCE_TIMING', 'observed_at' => null],
            'generic_fetched_at' => $record['fetched_at'], 'scheduled_start_at' => $record['scheduled_start_at'],
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED'];
    }

    public static function value(mixed $raw): string
    {
        return $raw === null ? 'NULL' : ($raw === '' ? 'EMPTY_STRING' : (is_string($raw) ? 'VALUE' : 'UNSUPPORTED_FORMAT'));
    }
}
