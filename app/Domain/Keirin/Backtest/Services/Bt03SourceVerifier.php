<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt03SourceVerificationDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03SourceArtifactRepository;
use DateTimeImmutable;
use RuntimeException;

class Bt03SourceVerifier
{
    public function __construct(
        private readonly Bt03SourceManifest $manifest,
        private readonly Bt03SourceArtifactRepository $source,
        private readonly Bt03SourceArtifactFingerprinter $fingerprinter,
        private readonly FinalHoldoutGuard $holdoutGuard,
    ) {}

    public function verify(): Bt03SourceVerificationDto
    {
        if ($this->manifest->computedHash() !== Bt03SourceManifest::HASH) {
            throw new RuntimeException('BT-03 source manifest identity was invalid.');
        }
        $run = $this->source->run() ?? throw new RuntimeException('BT-03 fixed source run 5 was unavailable.');
        $parameters = is_string($run->parameters)
            ? json_decode($run->parameters, true, 512, JSON_THROW_ON_ERROR)
            : $run->parameters;
        if ((int) $run->id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
            || $run->run_uuid !== Bt03SourceManifest::SOURCE_BT02_RUN_UUID
            || $run->backtest_code !== 'BT-02'
            || $run->status !== 'SUCCEEDED'
            || (int) $run->error_count !== 0
            || $run->source_manifest_hash !== Bt03SourceManifest::SOURCE_BT02_MANIFEST_HASH
            || ! is_array($parameters)
            || ($parameters['outcome_snapshot_manifest_hash'] ?? null) !== Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH
            || (int) $run->target_race_count !== 76458
            || (int) $run->predicted_race_count !== 75275
            || (int) $run->excluded_race_count !== 1183) {
            throw new RuntimeException('BT-03 fixed source run identity did not match run 5.');
        }
        $snapshotPath = $parameters['outcome_snapshot_path'] ?? null;
        if (! is_string($snapshotPath) || ! str_ends_with($snapshotPath, '/'.Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH)) {
            throw new RuntimeException('BT-03 source outcome snapshot path was invalid.');
        }

        $folds = iterator_to_array($this->source->folds(), false);
        if (array_map(fn (object $fold): string => (string) $fold->fold_code, $folds) !== ['WF_2023', 'WF_2024', 'WF_2025']) {
            throw new RuntimeException('BT-03 source required exactly the fixed three folds.');
        }
        foreach ($folds as $fold) {
            if ((int) $fold->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID || $fold->status !== 'SUCCEEDED') {
                throw new RuntimeException('BT-03 source fold identity was invalid.');
            }
            $this->holdoutGuard->assertAllowed(new FoldDefinitionDto(
                (string) $fold->fold_code,
                (int) $fold->sequence,
                new DateTimeImmutable((string) $fold->train_from),
                new DateTimeImmutable((string) $fold->train_to),
                new DateTimeImmutable((string) $fold->evaluation_from),
                new DateTimeImmutable((string) $fold->evaluation_to),
            ));
        }

        $specs = iterator_to_array($this->source->signalSpecs(), false);
        $entryStats = [];
        $diagnostic = $stratifier = 0;
        foreach ($specs as $spec) {
            if ((int) $spec->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID) {
                throw new RuntimeException('BT-03 source signal spec ownership was invalid.');
            }
            if ($spec->analysis_role === 'ENTRY_INCREMENTAL') {
                $entryStats[] = (string) $spec->stat_code;
            } elseif ($spec->analysis_role === 'DIAGNOSTIC_ONLY' && $spec->stat_code === 'STAT-33') {
                $diagnostic++;
            } elseif ($spec->analysis_role === 'RACE_STRATIFIER' && $spec->stat_code === 'STAT-41') {
                $stratifier++;
            } else {
                throw new RuntimeException('BT-03 source signal role contract was invalid.');
            }
        }
        sort($entryStats);
        $expectedStats = Bt03SourceManifest::ENTRY_STAT_CODES;
        sort($expectedStats);
        if (count($specs) !== 14 || $entryStats !== $expectedStats || $diagnostic !== 1 || $stratifier !== 1) {
            throw new RuntimeException('BT-03 source signal specs did not match the fixed 12+1+1 contract.');
        }

        $modelCount = $objectiveMatches = $optimizerMatches = 0;
        $roles = ['BASELINE_MATCHED' => 0, 'INCREMENTAL' => 0];
        foreach ($this->source->models() as $model) {
            $modelCount++;
            if ((int) $model->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
                || ! array_key_exists((string) $model->model_role, $roles)
                || $model->probability_semantics !== Bt03SourceManifest::PROBABILITY_SEMANTICS
                || ! str_starts_with((string) $model->convergence_status, 'CONVERGED_')) {
                throw new RuntimeException('BT-03 source model contract was invalid.');
            }
            $roles[$model->model_role]++;
            $objectiveMatches += (int) ($model->objective_version === Bt03SourceManifest::OBJECTIVE_VERSION);
            $optimizerMatches += (int) ($model->optimizer_version === Bt03SourceManifest::OPTIMIZER_VERSION);
        }
        if ($modelCount !== 432 || $roles !== ['BASELINE_MATCHED' => 216, 'INCREMENTAL' => 216]
            || $objectiveMatches !== 432 || $optimizerMatches !== 432) {
            throw new RuntimeException('BT-03 source model counts or versions were invalid.');
        }

        $metricCount = 0;
        foreach ($this->source->metrics() as $metric) {
            if ((int) $metric->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID) {
                throw new RuntimeException('BT-03 source metric ownership was invalid.');
            }
            $metricCount++;
        }
        $effectBinCount = 0;
        foreach ($this->source->effectBins() as $bin) {
            if ((int) $bin->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID) {
                throw new RuntimeException('BT-03 source effect bin ownership was invalid.');
            }
            $effectBinCount++;
        }
        if ($metricCount !== 648 || $effectBinCount !== 668) {
            throw new RuntimeException('BT-03 source metric or effect bin count was invalid.');
        }

        $actual = $this->fingerprinter->compute();
        $expected = $this->manifest->expectedFingerprints();
        foreach ($expected->canonical() as $name => $digest) {
            if (! hash_equals($digest, $actual->canonical()[$name])) {
                throw new RuntimeException("BT-03 source {$name} fingerprint mismatched.");
            }
        }
        if (! hash_equals($expected->manifestHash, $actual->manifestHash)) {
            throw new RuntimeException('BT-03 source artifact manifest fingerprint mismatched.');
        }

        return new Bt03SourceVerificationDto(
            Bt03SourceManifest::SOURCE_BT02_RUN_ID,
            count($folds),
            count($specs),
            $modelCount,
            $metricCount,
            $effectBinCount,
            $objectiveMatches,
            $optimizerMatches,
            $actual,
            $snapshotPath,
        );
    }
}
