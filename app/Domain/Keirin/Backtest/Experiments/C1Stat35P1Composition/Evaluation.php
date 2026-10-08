<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Evaluation as Shared;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use RuntimeException;

final class Evaluation
{
    public function __construct(private readonly Shared $evaluation) {}

    public function evaluate(array $source, array $paths, string $directory): array
    {
        $audit = [];
        $result = $this->evaluation->evaluate($source, $paths, $directory,
            static function (array $context, array $row, array $comparisons) use (&$audit): void {
                $year = $context['year'];
                $group = $row['winner_same'] ? 'winner_same' : 'winner_changed';
                $audit[$year][$group] ??= ['races' => 0, 'P1' => self::emptyChanges(), 'P2' => self::emptyChanges(), 'P3' => self::emptyChanges(),
                    'Hit3_eligible_races' => 0, 'Hit3_position_deltas' => [0, 0, 0], 'Hit3_total_delta' => 0];
                $a = &$audit[$year][$group];
                $a['races']++;
                $first = $comparisons['CANDIDATE-C1']['baseline'];
                $second = $comparisons['CANDIDATE-C1']['candidate'];
                $eligible = $first['POSITION_HIT_RATE_AT_3']['denominator'] !== 0.0;
                if ($eligible) {
                    $a['Hit3_eligible_races']++;
                }
                $positionDelta = 0;
                foreach ([1, 2, 3] as $p) {
                    $old = $first['POSITION_'.$p.'_ACCURACY'];
                    $new = $second['POSITION_'.$p.'_ACCURACY'];
                    $key = $old['denominator'] === 0.0 ? 'excluded' : ($old['numerator'] > 0.0
                        ? ($new['numerator'] > 0.0 ? 'both_hit' : 'C1_only') : ($new['numerator'] > 0.0 ? 'candidate_only' : 'both_miss'));
                    $a['P'.$p][$key]++;
                    if ($eligible) {
                        $delta = (int) ($new['numerator'] - $old['numerator']);
                        $a['Hit3_position_deltas'][$p - 1] += $delta;
                        $positionDelta += $delta;
                    }
                    if ($row['winner_same'] && $old !== $new) {
                        throw new RuntimeException('Same winner Primary contributions changed.');
                    }
                }
                $hitDelta = (int) ($second['POSITION_HIT_RATE_AT_3']['numerator'] - $first['POSITION_HIT_RATE_AT_3']['numerator']);
                if ($hitDelta !== $positionDelta) {
                    throw new RuntimeException('Hit3 eligible position contributions disagreed.');
                }
                $a['Hit3_total_delta'] += $hitDelta;
            });
        JsonlArtifact::json($directory.'/winner-group-evaluation.json', $audit);

        return $result + ['winner_groups' => $audit];
    }

    public function gates(array $result, bool $integrity): array
    {
        return $this->evaluation->gates($result, $integrity);
    }

    private static function emptyChanges(): array
    {
        return ['both_hit' => 0, 'C1_only' => 0, 'candidate_only' => 0, 'both_miss' => 0, 'excluded' => 0];
    }
}
