<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Input;

use RuntimeException;

final class SourceProjector
{
    public static function project(array $race, int $year, string $version): array
    {
        if ($version !== Contract::C1_VERSION || ! in_array($year, Contract::YEARS, true)) {
            throw new RuntimeException('Unsupported fixed C1 source version/year.');
        }
        Contract::keys($race, ['year', 'race_id', 'entries']);
        if (! is_array($race['entries']) || ! array_is_list($race['entries'])) {
            throw new RuntimeException('Source entries must be a list.');
        }
        foreach ($race['entries'] as &$entry) {
            Contract::keys($entry, [...Contract::ENTRY_KEYS, ...($year <= 2023 ? ['labels', 'rank', 'status'] : [])]);
            // These known fields are discarded without examining their values or selecting rows.
            if ($year <= 2023) {
                unset($entry['labels'], $entry['rank'], $entry['status']);
            }
        }
        unset($entry);
        Validator::race($race, $year);

        return $race;
    }
}
