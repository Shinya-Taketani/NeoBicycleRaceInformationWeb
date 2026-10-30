<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use InvalidArgumentException;

final class Stat20AcquiredHistoryContract
{
    public const VERSION = 'STAT-20-acquired-history-v2';

    public const GRADE_BREAKDOWN_VERSION = 'STAT-20-acquired-history-v3';

    public const BUILD_SERVICE_VERSION = 'ACQUIRED-HISTORY-RACE-AUDIT-v3';

    public const NEW_STATS = ['STAT-09', 'STAT-16', 'STAT-25', 'STAT-27', 'STAT-44'];

    public const VERSIONS = [
        'STAT-01' => 'STAT-01-existing-db-v1', 'STAT-07' => 'STAT-07-existing-db-v1',
        'STAT-08' => 'STAT-08-existing-db-v1', 'STAT-10' => 'STAT-10-existing-db-v1',
        'STAT-11' => 'STAT-11-existing-db-v1', 'STAT-12' => 'STAT-12-existing-db-v1',
        'STAT-23' => 'STAT-23-existing-db-v1', 'STAT-24' => 'STAT-24-existing-db-v1',
        'STAT-26' => 'STAT-26-existing-db-v1', 'STAT-31' => 'STAT-31-existing-db-v1',
        'STAT-32' => 'STAT-32-existing-db-v1', 'STAT-33' => 'STAT-33-existing-db-v1',
        'STAT-39' => 'STAT-39-existing-db-v1', 'STAT-41' => 'STAT-41-existing-db-v1',
        'STAT-42' => 'STAT-42-existing-db-v1', 'STAT-09' => 'STAT-09-acquired-career-v2',
        'STAT-16' => 'STAT-16-six-condition-performance-v3', 'STAT-25' => 'STAT-25-disqualification-history-v2',
        'STAT-27' => 'STAT-27-six-condition-experience-v3', 'STAT-44' => 'STAT-44-acquired-longitudinal-v2',
    ];

    public const MAX_SOURCE_RESULTS = 172;

    public static function versions(string $contract): array
    {
        return match ($contract) {
            self::VERSION => self::VERSIONS,
            self::GRADE_BREAKDOWN_VERSION => array_replace(self::VERSIONS, ['STAT-09' => 'STAT-09-acquired-career-grade-breakdown-v3']),
            default => throw new InvalidArgumentException('Unsupported STAT20 source contract.'),
        };
    }

    public static function stat09MetadataMatches(string $contract, array $metadata): bool
    {
        return match ($contract) {
            self::VERSION => ! array_key_exists('grade_projection_contract', $metadata)
                && (! array_key_exists('stat09_profile', $metadata) || $metadata['stat09_profile'] === 'legacy-v2'),
            self::GRADE_BREAKDOWN_VERSION => ($metadata['stat09_profile'] ?? null) === 'grade-breakdown-v3'
                && ($metadata['grade_projection_contract'] ?? null) === 'STAT09-SAVED-HISTORY-GRADES-v1',
            default => false,
        };
    }
}
