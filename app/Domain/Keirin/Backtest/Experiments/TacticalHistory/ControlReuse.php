<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Services\Bt03e06ModelReconstructor;
use Generator;
use RuntimeException;

final class ControlReuse
{
    public const CSV_COLUMNS = ['year', 'race_id', 'primary_position_1_bike', 'primary_position_2_bike', 'primary_position_3_bike',
        'winner_p1', 'selected_q2_given_winner', 'selected_q3_given_winner', 'primary_second_third_objective_score',
        'map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability',
        'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3', 'winner_tie_count', 'second_third_tie_count',
        'primary_technical_tiebreak_used', 'reconstruction_verified'];

    public function __construct(
        private readonly Bt03e06ModelReconstructor $models,
        private readonly LayoutBuilder $layouts,
        private readonly Dataset $datasets,
        private readonly EffectBinBuilder $bins,
        private readonly Predictor $predictor,
    ) {}

    public function predict(int $year, array $training, string $input, array $model, string $csv, string $directory): array
    {
        $reconstructed = $this->models->reconstruct($year, $model);
        $layout = $this->layouts->build(fn () => $this->datasets->raw($training, false));
        if ($layout->canonicalBins() !== $reconstructed->layout->canonicalBins()) {
            throw new ControlReuseException('C0 training bins/support differed from the original model.');
        }
        $manifest = JsonlArtifact::write($directory.'/predictions.jsonl', (function () use ($year, $input, $reconstructed, $csv): Generator {
            $expected = $this->csvRows($csv, $year);
            $expected->rewind();
            foreach ($this->datasets->raw([$input], false, true) as $race) {
                foreach ($race['entries'] as &$entry) {
                    $entry['bins'] = $reconstructed->layout->assign($entry['signals'], $this->bins);
                    unset($entry['signals']);
                }
                unset($entry);
                $prediction = $this->predictor->predict($race, $reconstructed->fit, true);
                if (! $expected->valid()) {
                    throw new ControlReuseException('C0 prediction count exceeded original E06.');
                }
                $this->assertDecision($prediction['decision'], $expected->current());
                yield $prediction;
                $expected->next();
            }
            if ($expected->valid()) {
                throw new ControlReuseException('C0 omitted original E06 races.');
            }
        })());
        JsonlArtifact::json($directory.'/model.json', $model);
        $report = ['status' => 'REUSED_MODEL_RECONSTRUCTED_PREDICTIONS_VERIFIED', 'new_fit_count' => 0,
            'source_csv_sha256' => hash_file('sha256', $csv), 'training_bins_and_support_exact' => true,
            'all_decoder_csv_fields_exact' => true, 'prediction_manifest' => $manifest];
        JsonlArtifact::json($directory.'/reuse.json', $report);

        return $report;
    }

    private function csvRows(string $path, int $year): Generator
    {
        $handle = fopen($path, 'rb');
        try {
            $header = fgetcsv($handle, escape: '');
            if ($header !== self::CSV_COLUMNS) {
                throw new RuntimeException('Original E06 decoder columns were invalid.');
            }
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $row = array_combine($header, $values);
                if ((int) $row['year'] === $year) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertDecision(array $actual, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                throw new RuntimeException('Unknown original E06 decoder column: '.$key);
            }
            $current = $actual[$key];
            $same = match (true) {
                is_array($current) => implode('-', $current) === $value,
                is_bool($current) => (int) $current === (int) $value,
                is_int($current) => $current === (int) $value,
                is_float($current) => $current === (float) $value,
                default => $current === $value,
            };
            if (! $same) {
                throw new ControlReuseException('C0 reconstructed E06 mismatch at race '.$actual['race_id'].' field '.$key);
            }
        }
    }
}
