<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\AgariC1Input\Index;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Exact;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\History;
use RuntimeException;

final class Trend
{
    public static function calculate(array $audit, array $history): array
    {
        $context = $audit['context'];
        $target = $context['target'];
        $reasons = $context['reasons'];
        $candidates = [];
        if ($reasons === []) {
            $evidence = $context['evidence'];
            foreach ($history as $row) {
                if (($row['source'] ?? null) !== 'keirin_jp'
                    || ($row['measurement_definition_id'] ?? null) !== InputContract::DEFINITION
                    || $row['external_player_id'] !== $evidence['external_player_id'] || $row['race_class'] !== $context['class']
                    || $row['history_context_eligible'] !== true) {
                    $reasons[] = 'HISTORY_IDENTITY_CLASS_OR_DEFINITION_MISMATCH';

                    continue;
                }
                $m = $row['meeting'];
                if ($m['meeting_id'] === $target['meeting_id'] || $m['ends_on'] >= $target['meeting_start']) {
                    continue;
                }
                Index::number($row['meeting_percentile_mean']);
                $candidates[] = $row;
            }
        }
        // Selection, overlap checks, NULL slots and exact arithmetic are the frozen implementation.
        $result = History::calculate($candidates, $target['meeting_start'], '2022-01-01', $reasons);
        $window = $result['windows'][6];
        unset($window['median'], $window['population_variance']);
        Files::same($audit['window'], $window, 'INPUT02 observed window unchanged');
        Files::same([$audit['float']], [Index::number($window['mean'])], 'INPUT02 mean6 unchanged');
        $exact = $result['recent3_minus_previous3_meeting_percentile'];
        $value = $exact === null ? null : (float) Exact::value(Exact::rational($exact))['decimal'];
        if ($value !== null && (! is_finite($value) || abs($value) > 1.0)) {
            throw new RuntimeException('Invalid exact trend conversion.');
        }

        return ['stat35_mean6' => $audit['float'], 'stat35_trend6' => $value, 'exact' => $exact,
            'null_reason' => $result['trend_null_reason'], 'selected_meetings' => $window['meetings']];
    }
}
