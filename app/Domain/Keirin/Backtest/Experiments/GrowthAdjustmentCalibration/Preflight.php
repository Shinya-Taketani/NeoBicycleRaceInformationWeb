<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel;
use Generator;
use RuntimeException;

final class Preflight
{
    public function __construct(private readonly Dataset $dataset, private readonly EffectBinBuilder $bins, private readonly Adjustment $adjustment, private readonly Metrics $metrics) {}

    public function inputs(array $paths, int $year, LoadedModel $model, Workspace $workspace, array &$audit): Generator
    {
        $predictions = JsonlArtifact::read($paths['prediction']);
        $labels = JsonlArtifact::read($paths['labels']);
        $contributions = JsonlArtifact::read($paths['contributions']);
        foreach ([$predictions, $labels, $contributions] as $stream) {
            $stream->rewind();
        }
        $audit = ['races' => 0, 'entries' => 0, 'exact_predictions' => 0, 'metrics' => Metrics::empty()];
        $hash = hash_init('sha256');
        $raw = fn () => $this->dataset->raw([$paths['input']], true, true);
        foreach ($this->dataset->binned($raw, $model->layout, $this->bins) as $race) {
            if ($race['year'] !== $year || ! $predictions->valid() || ! $labels->valid() || ! $contributions->valid()) {
                throw new RuntimeException('Preflight source alignment mismatch.');
            }
            $joined = $workspace->join($race);
            $prediction = $this->adjustment->predict($race, $joined['growth'], 0, $model->fit);
            Files::same($predictions->current(), $prediction, 'w=0 full probabilities/decision');
            hash_update($hash, Files::canonical($prediction)."\n");
            $values = $this->metrics->contribution(Metrics::context($labels->current(), $race), $prediction);
            $stored = $contributions->current();
            if ($stored['race_id'] !== $race['race_id']) {
                throw new RuntimeException('Contribution identity mismatch.');
            }
            Files::same(array_intersect_key($stored['C1-STAT01']['candidate'], array_flip(Contract::METRICS)), $values, 'w=0 frozen primary contributions');
            Metrics::add($audit['metrics'], $values);
            $audit['races']++;
            $audit['exact_predictions']++;
            $audit['entries'] += count($race['entries']);
            yield $joined;
            foreach ([$predictions, $labels, $contributions] as $stream) {
                $stream->next();
            }
        }
        foreach ([$predictions, $labels, $contributions] as $stream) {
            if ($stream->valid()) {
                throw new RuntimeException('Extra preflight source rows.');
            }
        }
        $audit['canonical_prediction_sha256'] = hash_final($hash);
    }
}
