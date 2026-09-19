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
    public function __construct(private readonly Dataset $dataset, private readonly EffectBinBuilder $bins, private readonly Adjustment $adjustment, private readonly Metrics $metrics, private readonly OutcomeReader $outcomes) {}

    public function predictionInputs(string $input, string $predictionPath, int $year, LoadedModel $model, Workspace $workspace, array &$audit): Generator
    {
        $predictions = JsonlArtifact::read($predictionPath);
        $predictions->rewind();
        $audit = ['races' => 0, 'entries' => 0, 'exact_predictions' => 0];
        $hash = hash_init('sha256');
        $raw = fn () => $this->dataset->raw([$input], true, true);
        foreach ($this->dataset->binned($raw, $model->layout, $this->bins) as $race) {
            if ($race['year'] !== $year || ! $predictions->valid()) {
                throw new RuntimeException('Preflight source alignment mismatch.');
            }
            $joined = $workspace->join($race);
            $prediction = $this->adjustment->predict($race, $joined['growth'], 0, $model->fit);
            Files::same($predictions->current(), $prediction, 'w=0 full probabilities/decision');
            hash_update($hash, Files::canonical($prediction)."\n");
            $audit['races']++;
            $audit['exact_predictions']++;
            $audit['entries'] += count($race['entries']);
            yield $joined;
            $predictions->next();
        }
        if ($predictions->valid()) {
            throw new RuntimeException('Extra preflight prediction rows.');
        }
        $audit['canonical_prediction_sha256'] = hash_final($hash);
    }

    public function verifyBaseline(int $year, string $input, array $paths, TemporalAccess $access): array
    {
        $access->phase($year, $year.'_OUTCOME_BASELINE');
        $predictions = JsonlArtifact::read($paths['prediction']);
        $labels = $this->outcomes->read($year, 'labels', $paths['labels'], $access);
        $contributions = $this->outcomes->read($year, 'contributions', $paths['contributions'], $access);
        foreach ([$predictions, $labels, $contributions] as $stream) {
            $stream->rewind();
        }
        $audit = ['races' => 0, 'metrics' => Metrics::empty()];
        foreach (JsonlArtifact::read($input) as $row) {
            $race = $row['race'];
            if ($race['year'] !== $year || ! $predictions->valid() || ! $labels->valid() || ! $contributions->valid()
                || $predictions->current()['probabilities']['race_id'] !== $race['race_id']) {
                throw new RuntimeException('Baseline source alignment mismatch.');
            }
            $values = $this->metrics->contribution(Metrics::context($labels->current(), $race), $predictions->current());
            $stored = $contributions->current();
            if ($stored['race_id'] !== $race['race_id']) {
                throw new RuntimeException('Contribution identity mismatch.');
            }
            Files::same(array_intersect_key($stored['C1-STAT01']['candidate'], array_flip(Contract::METRICS)), $values, 'w=0 frozen primary contributions');
            Metrics::add($audit['metrics'], $values);
            $audit['races']++;
            foreach ([$predictions, $labels, $contributions] as $stream) {
                $stream->next();
            }
        }
        foreach ([$predictions, $labels, $contributions] as $stream) {
            if ($stream->valid()) {
                throw new RuntimeException('Extra preflight source rows.');
            }
        }
        if ($year === 2025) {
            $access->requireSelection();
        }

        return $audit;
    }
}
