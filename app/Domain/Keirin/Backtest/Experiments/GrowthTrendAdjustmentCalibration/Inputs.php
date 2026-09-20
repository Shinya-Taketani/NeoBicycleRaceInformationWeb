<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel;
use Generator;
use RuntimeException;

final class Inputs
{
    public function __construct(private readonly Dataset $dataset, private readonly EffectBinBuilder $bins, private readonly Adjustment $adjustment) {}

    public function build(int $year, array $paths, LoadedModel $model, Workspace $w, array &$audit): Generator
    {
        Contract::year($year);
        $predictions = JsonlArtifact::read($paths['prediction']);
        $hash = hash_init('sha256');
        $audit = ['races' => 0, 'entries' => 0, 'exact_predictions' => 0];
        $w->db->beginTransaction();
        foreach ($this->dataset->binned(fn () => $this->dataset->raw([$paths['input']], true, true), $model->layout, $this->bins) as $race) {
            if ($race['year'] !== $year || ! $predictions->valid()) {
                throw new RuntimeException('Incomplete fixed predictions.');
            }
            $base = $predictions->current();
            $p1 = array_column($base['probabilities']['entries'], 'position_1_probability');
            rsort($p1, SORT_NUMERIC);
            if (count($p1) !== count($race['entries'])) {
                throw new RuntimeException('Missing fixed P1.');
            }
            $joined = $w->join($race, $p1[0] - $p1[1]);
            $replay = $this->adjustment->predict($joined, 0, 1.0, $model->fit);
            Files::same($base, $replay, 'w=0 full probability/decision');
            hash_update($hash, Files::canonical($replay)."\n");
            $audit['races']++;
            $audit['exact_predictions']++;
            $audit['entries'] += count($race['entries']);
            yield $joined;
            $predictions->next();
        }
        if ($predictions->valid()) {
            throw new RuntimeException('Extra fixed predictions.');
        }
        $w->db->commit();
        $audit['semantic_sha256'] = hash_final($hash);
    }
}
