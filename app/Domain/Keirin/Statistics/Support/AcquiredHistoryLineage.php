<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use UnexpectedValueException;

final class AcquiredHistoryLineage
{
    public const CONTRACT = 'ACQUIRED-HISTORY-STAT01-LINEAGE-v1';

    public static function attach(array $evidence, int $runId, string $inputHash): array
    {
        $binding = ['stat01_run_id' => $runId, 'stat01_input_hash' => $inputHash, 'lineage_contract' => self::CONTRACT];
        foreach ($binding as $key => $value) {
            if (array_key_exists($key, $evidence) && $evidence[$key] !== $value) {
                throw new UnexpectedValueException('Conflicting acquired-history lineage: '.$key);
            }
        }

        return $evidence + $binding;
    }
}
