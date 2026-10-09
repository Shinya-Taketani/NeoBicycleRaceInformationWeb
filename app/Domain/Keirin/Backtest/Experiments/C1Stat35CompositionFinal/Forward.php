<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03CompensatedSum;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\ProbabilityCalculator;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\LoadedModel as C2;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel as C1;

final class Forward
{
    public function __construct(private readonly EffectBinBuilder $bins, private readonly ProbabilityCalculator $calculator,
        private readonly Bt03e06WinnerConditionedDecoder $decoder) {}

    public function predict(array $race, C1 $c1, C2 $c2): array
    {
        Input::validate($race);
        $utilityRace = $race;
        foreach ($utilityRace['entries'] as &$entry) {
            $sixteen = [...$entry['signals'], ...$entry['history']];
            $c1Bins = $c1->layout->assign($sixteen, $this->bins);
            $c2Bins = $c2->layout->assign([...$sixteen, $entry['stat35_mean6']], $this->bins);
            $utilities = [];
            foreach (['POSITION_1', 'POSITION_2', 'POSITION_3'] as $position) {
                $model = $position === 'POSITION_1' ? $c2 : $c1;
                $assigned = $position === 'POSITION_1' ? $c2Bins : $c1Bins;
                $sum = new Bt03e03CompensatedSum;
                $sum->add((float) $entry['anchor']);
                foreach ($assigned as $offset) {
                    if ($offset !== null) {
                        $sum->add($model->fit->coefficients[$position][$offset]);
                    }
                }
                $utilities[$position] = $sum->value();
            }
            $entry = ['id' => $entry['id'], 'bike' => $entry['bike'], 'raw' => $entry['raw'],
                'stat01_rank' => $entry['stat01_rank'], 'anchor' => $entry['anchor'], 'utilities' => $utilities];
        }
        unset($entry);
        $probabilities = $this->calculator->predict($utilityRace);

        return ['probabilities' => $probabilities, 'decision' => $this->decoder->decode($probabilities)];
    }
}
