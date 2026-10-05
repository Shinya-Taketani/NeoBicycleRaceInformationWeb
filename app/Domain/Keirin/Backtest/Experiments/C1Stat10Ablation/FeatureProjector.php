<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use RuntimeException;

final class FeatureProjector
{
    public static function vector(array $values, ?array $names = null): array
    {
        Files::same(Contract::baselineFeatures(), $names ?? Contract::baselineFeatures(), 'full feature names/order');
        if (! array_is_list($values) || count($values) !== 16) {
            throw new RuntimeException('Full feature vector must have 16 values.');
        }
        $retained = [];
        foreach (Contract::projection()['full_to_retained_mapping'] as $mapping) {
            if ($mapping['retained_index'] !== null) {
                $retained[$mapping['retained_index']] = $values[$mapping['full_index']];
            }
        }

        return $retained;
    }

    public static function race(array $race, int $year): array
    {
        Validator::race($race, $year);
        foreach ($race['entries'] as &$entry) {
            $entry['signals'] = self::vector([...$entry['signals'], ...$entry['history']]);
            unset($entry['history'], $entry['history_status']);
        }
        unset($entry);

        return $race;
    }
}
