<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt03SourceArtifactFingerprintsDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03SourceArtifactRepository;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use RuntimeException;

class Bt03SourceArtifactFingerprinter
{
    public const VERSION = 'BT03-BT02-ARTIFACT-FINGERPRINT-v1';

    public function __construct(
        private readonly Bt03SourceArtifactRepository $source,
        private readonly Bt02ModelArtifactHasher $hasher,
    ) {}

    public function compute(): Bt03SourceArtifactFingerprintsDto
    {
        $run = $this->source->run() ?? throw new RuntimeException('BT-03 source run was unavailable.');
        $runAndFold = $this->hashRows((function () use ($run): iterable {
            yield $this->normalize($run, ['parameters'], [], [
                'id', 'target_race_count', 'predicted_race_count', 'excluded_race_count', 'error_count',
            ], ['started_at', 'finished_at', 'created_at', 'updated_at']) + ['artifact_type' => 'RUN'];
            foreach ($this->source->folds() as $fold) {
                yield $this->normalize($fold, [], [], [
                    'id', 'backtest_run_id', 'sequence', 'target_race_count', 'predicted_race_count', 'excluded_race_count',
                ], ['started_at', 'finished_at', 'created_at', 'updated_at']) + ['artifact_type' => 'FOLD'];
            }
        })());
        $specs = $this->hashRows((function (): iterable {
            foreach ($this->source->signalSpecs() as $spec) {
                yield $this->normalize(
                    $spec,
                    ['operational_allowed_quality_reasons', 'parameters'],
                    [],
                    ['id', 'backtest_run_id'],
                    ['created_at', 'updated_at'],
                );
            }
        })());
        $models = $this->hashRows((function (): iterable {
            foreach ($this->source->models() as $model) {
                yield $this->normalize(
                    $model,
                    ['feature_names', 'scaler_mean', 'scaler_sd', 'lambda_candidates', 'coefficients'],
                    ['selected_lambda', 'intercept', 'final_objective'],
                    ['id', 'backtest_run_id', 'backtest_fold_id', 'backtest_signal_spec_id', 'iterations'],
                    ['created_at', 'updated_at'],
                );
            }
        })());
        $metrics = $this->hashRows((function (): iterable {
            foreach ($this->source->metrics() as $metric) {
                yield $this->normalize(
                    $metric,
                    ['metadata'],
                    ['baseline_value', 'incremental_value', 'delta_value', 'ci_lower', 'ci_upper'],
                    ['id', 'backtest_run_id', 'backtest_fold_id', 'backtest_signal_spec_id', 'sample_count', 'race_count', 'bootstrap_iterations', 'bootstrap_seed'],
                    ['calculated_at', 'created_at', 'updated_at'],
                );
            }
        })());
        $effectBins = $this->hashRows((function (): iterable {
            foreach ($this->source->effectBins() as $bin) {
                yield $this->normalize(
                    $bin,
                    ['metadata'],
                    ['lower_bound', 'upper_bound'],
                    ['id', 'backtest_run_id', 'backtest_fold_id', 'backtest_signal_spec_id', 'bin_index', 'training_sample_count'],
                    ['created_at', 'updated_at'],
                );
            }
        })());
        $components = [
            'run_and_fold' => $runAndFold,
            'signal_specs' => $specs,
            'models' => $models,
            'metrics' => $metrics,
            'effect_bins' => $effectBins,
        ];

        return new Bt03SourceArtifactFingerprintsDto(
            $runAndFold,
            $specs,
            $models,
            $metrics,
            $effectBins,
            $this->hasher->hash(['version' => self::VERSION, 'components' => $components]),
        );
    }

    /** @param iterable<array<string, mixed>> $rows */
    private function hashRows(iterable $rows): string
    {
        $context = hash_init('sha256');
        $count = 0;
        foreach ($rows as $row) {
            hash_update($context, $this->hasher->hash($row)."\n");
            $count++;
        }
        hash_update($context, "rows={$count}\n");

        return hash_final($context);
    }

    /**
     * @param  list<string>  $jsonColumns
     * @param  list<string>  $floatColumns
     * @param  list<string>  $integerColumns
     * @param  list<string>  $excludedColumns
     * @return array<string, mixed>
     */
    private function normalize(object $row, array $jsonColumns, array $floatColumns, array $integerColumns, array $excludedColumns): array
    {
        $normalized = [];
        foreach ((array) $row as $column => $value) {
            if (in_array($column, $excludedColumns, true)) {
                continue;
            }
            if (in_array($column, $jsonColumns, true) && $value !== null) {
                $value = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
            } elseif (in_array($column, $floatColumns, true) && $value !== null) {
                $value = (float) $value;
            } elseif (in_array($column, $integerColumns, true) && $value !== null) {
                $value = (int) $value;
            }
            $normalized[$column] = $value;
        }

        return $normalized;
    }
}
