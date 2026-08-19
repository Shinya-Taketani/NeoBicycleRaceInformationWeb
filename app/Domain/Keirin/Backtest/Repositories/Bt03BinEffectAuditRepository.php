<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\DTO\Bt03ComputedBinEffectDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionSummaryDto;
use App\Domain\Keirin\Backtest\Services\Bt03EffectManifestService;
use App\Domain\Keirin\Backtest\Services\Bt03ProductionContract;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt03EffectHasher;
use App\Models\BacktestBinEffectScope;
use App\Models\BacktestFold;
use App\Models\BacktestRun;
use App\Models\BacktestSignalSpec;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Bt03BinEffectAuditRepository
{
    public function __construct(
        private readonly Bt03ProductionContract $contract,
        private readonly Bt03EffectManifestService $manifests,
        private readonly Bt03EffectHasher $effectHasher,
    ) {}

    public function assertResumeAllowed(int $runId): BacktestRun
    {
        $run = BacktestRun::query()->findOrFail($runId);
        $parameters = $this->parameters($run);
        if ($run->backtest_code !== 'BT-03'
            || $run->status === 'SUCCEEDED'
            || ! in_array($run->status, ['RUNNING', 'PARTIALLY_SUCCEEDED', 'FAILED'], true)
            || $run->calculation_version !== Bt03BinEffectCalculator::CALCULATION_VERSION
            || $run->holdout_policy !== Bt03ProductionContract::HOLDOUT_POLICY
            || $run->source_manifest_version !== Bt03SourceManifest::VERSION
            || $run->source_manifest_hash !== Bt03SourceManifest::HASH
            || $run->prediction_rule_version !== Bt03ProductionContract::PREDICTION_RULE_VERSION
            || ($parameters['runtime']['resume_allowed'] ?? true) === false) {
            throw new RuntimeException('BT-03 Production run was not eligible for resume.');
        }

        return $run;
    }

    public function createRun(Bt03PreflightSummaryDto $preflight): BacktestRun
    {
        return DB::transaction(function () use ($preflight): BacktestRun {
            $sourceRun = $this->sourceRun();
            $sourceFolds = $this->sourceFolds();
            $sourceSpecs = $this->sourceSpecs();
            $now = new DateTimeImmutable('now');
            $run = BacktestRun::query()->create([
                'run_uuid' => (string) Str::uuid(),
                'backtest_code' => 'BT-03',
                'calculation_version' => Bt03BinEffectCalculator::CALCULATION_VERSION,
                'status' => 'RUNNING',
                'holdout_policy' => Bt03ProductionContract::HOLDOUT_POLICY,
                'source_manifest_version' => Bt03SourceManifest::VERSION,
                'source_manifest_hash' => Bt03SourceManifest::HASH,
                'prediction_rule_version' => Bt03ProductionContract::PREDICTION_RULE_VERSION,
                'parameters' => $this->initialParameters($preflight),
                'started_at' => $now,
            ]);

            $targetFolds = [];
            foreach ($sourceFolds as $foldCode => $sourceFold) {
                $targetFolds[$foldCode] = BacktestFold::query()->create([
                    'backtest_run_id' => $run->id,
                    'fold_code' => $foldCode,
                    'sequence' => (int) $sourceFold->sequence,
                    'train_from' => $sourceFold->train_from,
                    'train_to' => $sourceFold->train_to,
                    'evaluation_from' => $sourceFold->evaluation_from,
                    'evaluation_to' => $sourceFold->evaluation_to,
                    'status' => 'RUNNING',
                    'started_at' => $now,
                ]);
            }

            $targetSpecs = [];
            foreach ($sourceSpecs as $statCode => $sourceSpec) {
                $targetSpecs[$statCode] = BacktestSignalSpec::query()->create([
                    'backtest_run_id' => $run->id,
                    'stat_code' => $statCode,
                    'subject_type' => $sourceSpec->subject_type,
                    'analysis_role' => $sourceSpec->analysis_role,
                    'primary_feature_code' => $sourceSpec->primary_feature_code,
                    'primary_feature_path' => $sourceSpec->primary_feature_path,
                    'transform_code' => $sourceSpec->transform_code,
                    'strict_policy_version' => $sourceSpec->strict_policy_version,
                    'operational_policy_version' => $sourceSpec->operational_policy_version,
                    'operational_allowed_quality_reasons' => $this->json($sourceSpec->operational_allowed_quality_reasons),
                    'source_manifest_version' => Bt03SourceManifest::VERSION,
                    'source_manifest_hash' => Bt03SourceManifest::HASH,
                    'parameters' => [
                        'source_bt02_signal_spec_id' => (int) $sourceSpec->id,
                        'execution' => 'BT03_BIN_EFFECT',
                    ],
                ]);
            }

            $sourceBinCount = 0;
            foreach ($this->contract->scopes() as $definition) {
                $sourceFold = $sourceFolds[$definition->foldCode];
                $sourceSpec = $sourceSpecs[$definition->statCode];
                [$binCount, $boundariesHash] = $this->sourceBinIdentity(
                    (int) $sourceFold->id,
                    (int) $sourceSpec->id,
                    $definition->cohortCode,
                );
                $sourceBinCount += $binCount;
                BacktestBinEffectScope::query()->create([
                    'backtest_run_id' => $run->id,
                    'backtest_fold_id' => $targetFolds[$definition->foldCode]->id,
                    'backtest_signal_spec_id' => $targetSpecs[$definition->statCode]->id,
                    'source_backtest_run_id' => $sourceRun->id,
                    'source_backtest_fold_id' => $sourceFold->id,
                    'source_backtest_signal_spec_id' => $sourceSpec->id,
                    'cohort_code' => $definition->cohortCode,
                    'status' => 'PENDING',
                    'attempt_count' => 0,
                    'expected_training_bin_count' => $binCount,
                    'source_boundaries_hash' => $boundariesHash,
                    'bootstrap_iterations' => Bt03ProductionContract::BOOTSTRAP_ITERATIONS,
                    'bootstrap_seed' => Bt03ProductionContract::BOOTSTRAP_SEED,
                ]);
            }
            if ($sourceBinCount !== $preflight->source->effectBinCount) {
                throw new RuntimeException('BT-03 Production source bin scope total was invalid.');
            }

            return $run;
        });
    }

    public function resumeRun(int $runId, Bt03PreflightSummaryDto $preflight): BacktestRun
    {
        return DB::transaction(function () use ($runId, $preflight): BacktestRun {
            $run = BacktestRun::query()->lockForUpdate()->findOrFail($runId);
            $this->assertRunContract($run, $preflight);
            $this->assertTargetStructure($run);
            $parameters = $this->parameters($run);
            $runtime = $parameters['runtime'];
            $runtime['execution_attempt'] = (int) ($runtime['execution_attempt'] ?? 1) + 1;
            $runtime['resume_count'] = (int) ($runtime['resume_count'] ?? 0) + 1;
            $runtime['last_failure'] = null;
            $parameters['runtime'] = $runtime;
            $run->forceFill([
                'status' => 'RUNNING',
                'parameters' => $parameters,
                'error_count' => 0,
                'error_summary' => null,
                'finished_at' => null,
            ])->save();
            BacktestFold::query()
                ->where('backtest_run_id', $run->id)
                ->where('status', '!=', 'SUCCEEDED')
                ->update(['status' => 'RUNNING', 'finished_at' => null, 'updated_at' => new DateTimeImmutable('now')]);

            return $run->refresh();
        });
    }

    /** @return list<BacktestBinEffectScope> */
    public function scopes(BacktestRun $run): array
    {
        $rows = BacktestBinEffectScope::query()
            ->select('backtest_bin_effect_scopes.*', 'backtest_folds.fold_code', 'backtest_signal_specs.stat_code')
            ->join('backtest_folds', 'backtest_folds.id', '=', 'backtest_bin_effect_scopes.backtest_fold_id')
            ->join('backtest_signal_specs', 'backtest_signal_specs.id', '=', 'backtest_bin_effect_scopes.backtest_signal_spec_id')
            ->where('backtest_bin_effect_scopes.backtest_run_id', $run->id)
            ->get()
            ->keyBy(fn (BacktestBinEffectScope $scope): string => $this->scopeKey(
                (string) $scope->fold_code,
                (string) $scope->stat_code,
                (string) $scope->cohort_code,
            ));

        $ordered = [];
        foreach ($this->contract->scopes() as $definition) {
            $scope = $rows->get($this->scopeKey($definition->foldCode, $definition->statCode, $definition->cohortCode));
            if (! $scope instanceof BacktestBinEffectScope) {
                throw new RuntimeException('BT-03 Production scope ledger was incomplete.');
            }
            $ordered[] = $scope;
        }
        if ($rows->count() !== Bt03ProductionContract::SCOPE_COUNT) {
            throw new RuntimeException('BT-03 Production scope ledger exceeded the fixed contract.');
        }

        return $ordered;
    }

    public function startScope(BacktestBinEffectScope $scope): BacktestBinEffectScope
    {
        return DB::transaction(function () use ($scope): BacktestBinEffectScope {
            $locked = BacktestBinEffectScope::query()->lockForUpdate()->findOrFail($scope->id);
            if (! in_array($locked->status, ['PENDING', 'FAILED', 'RUNNING'], true)
                || $this->effectCount($locked) !== 0) {
                throw new RuntimeException('BT-03 Production incomplete scope was not safe to start.');
            }
            $interruption = $locked->status === 'RUNNING'
                ? 'INTERRUPTED_BEFORE_RESUME at '.(new DateTimeImmutable('now'))->format(DATE_ATOM)
                : $locked->last_interruption_summary;
            $locked->forceFill([
                'status' => 'RUNNING',
                'attempt_count' => (int) $locked->attempt_count + 1,
                'evaluation_row_count' => 0,
                'evaluation_race_count' => 0,
                'unseen_row_count' => 0,
                'effect_count' => 0,
                'spool_byte_count' => 0,
                'maximum_bin_sample_count' => 0,
                'maximum_bin_race_count' => 0,
                'effect_manifest_hash' => null,
                'error_summary' => null,
                'last_interruption_summary' => $interruption,
                'started_at' => new DateTimeImmutable('now'),
                'finished_at' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    public function persistScope(
        BacktestBinEffectScope $scope,
        string $foldCode,
        string $statCode,
        Bt03EvaluationReplaySummaryDto $summary,
    ): void {
        DB::transaction(function () use ($scope, $foldCode, $statCode, $summary): void {
            $locked = BacktestBinEffectScope::query()->lockForUpdate()->findOrFail($scope->id);
            if ($locked->status !== 'RUNNING' || $this->effectCount($locked) !== 0) {
                throw new RuntimeException('BT-03 Production scope was not empty and RUNNING before persistence.');
            }
            $this->assertSourceOwnership($locked, $summary->effects);
            $manifest = $this->manifests->fromComputed($locked, $foldCode, $statCode, $summary->effects);
            $now = new DateTimeImmutable('now');
            foreach ($summary->effects as $effect) {
                DB::table('backtest_bin_effects')->insert($this->effectRow($locked, $effect, $now));
            }
            $this->verifyPersistedScope($locked, $foldCode, $statCode, $summary->effects, $manifest);
            $locked->forceFill([
                'status' => 'SUCCEEDED',
                'evaluation_row_count' => $summary->evaluationRowCount,
                'evaluation_race_count' => $summary->evaluationRaceCount,
                'unseen_row_count' => $summary->unseenRowCount,
                'effect_count' => count($summary->effects),
                'spool_byte_count' => $summary->spoolByteCount,
                'maximum_bin_sample_count' => $summary->maximumBinSampleCount,
                'maximum_bin_race_count' => $summary->maximumBinRaceCount,
                'effect_manifest_hash' => $manifest,
                'error_summary' => null,
                'finished_at' => $now,
            ])->save();
            $this->updateProgress($locked, $foldCode, $statCode, $now);
        });
    }

    public function failScope(BacktestBinEffectScope $scope, Throwable $failure): void
    {
        DB::transaction(function () use ($scope, $failure): void {
            $locked = BacktestBinEffectScope::query()->lockForUpdate()->findOrFail($scope->id);
            if ($this->effectCount($locked) !== 0) {
                throw new RuntimeException('BT-03 Production failed scope contained partial effects.');
            }
            $locked->forceFill([
                'status' => 'FAILED',
                'evaluation_row_count' => 0,
                'evaluation_race_count' => 0,
                'unseen_row_count' => 0,
                'effect_count' => 0,
                'spool_byte_count' => 0,
                'maximum_bin_sample_count' => 0,
                'maximum_bin_race_count' => 0,
                'effect_manifest_hash' => null,
                'error_summary' => $this->error($failure),
                'finished_at' => new DateTimeImmutable('now'),
            ])->save();
        });
    }

    public function verifySucceededScope(BacktestBinEffectScope $scope): int
    {
        if ($scope->status !== 'SUCCEEDED') {
            throw new RuntimeException('BT-03 Production attempted to verify a non-succeeded scope.');
        }
        $foldCode = (string) ($scope->fold_code ?: DB::table('backtest_folds')->where('id', $scope->backtest_fold_id)->value('fold_code'));
        $statCode = (string) ($scope->stat_code ?: DB::table('backtest_signal_specs')->where('id', $scope->backtest_signal_spec_id)->value('stat_code'));
        $rows = $this->verifyPersistedScope($scope, $foldCode, $statCode, null, (string) $scope->effect_manifest_hash);
        if ((int) $scope->effect_count !== count($rows)) {
            throw new RuntimeException('BT-03 Production scope ledger effect count mismatched persisted effects.');
        }

        return count($rows);
    }

    public function finalizeSuccess(BacktestRun $run, int $skippedScopeCount = 0): Bt03ProductionSummaryDto
    {
        if ($skippedScopeCount < 0 || $skippedScopeCount > Bt03ProductionContract::SCOPE_COUNT) {
            throw new RuntimeException('BT-03 Production skipped scope count was invalid.');
        }
        $scopes = $this->scopes($run);
        $effectCount = $unseenScopes = 0;
        foreach ($scopes as $scope) {
            $effectCount += $this->verifySucceededScope($scope);
            $unseenScopes += (int) ((int) $scope->unseen_row_count > 0);
        }
        $scopeRows = array_map(fn (BacktestBinEffectScope $scope): object => (object) [
            'fold_code' => $scope->fold_code,
            'stat_code' => $scope->stat_code,
            'cohort_code' => $scope->cohort_code,
            'effect_manifest_hash' => $scope->effect_manifest_hash,
        ], $scopes);
        $runManifest = $this->manifests->run($scopeRows);

        DB::transaction(function () use ($run, $effectCount, $unseenScopes, $runManifest): void {
            $locked = BacktestRun::query()->lockForUpdate()->findOrFail($run->id);
            $now = new DateTimeImmutable('now');
            BacktestFold::query()->where('backtest_run_id', $run->id)->update([
                'status' => 'SUCCEEDED',
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
            $parameters = $this->parameters($locked);
            $parameters['runtime']['completed_scope_count'] = Bt03ProductionContract::SCOPE_COUNT;
            $parameters['runtime']['resume_allowed'] = false;
            $parameters['runtime']['resume_block_reason'] = 'RUN_SUCCEEDED';
            $parameters['result'] = [
                'effect_manifest_hash' => $runManifest,
                'effect_count' => $effectCount,
                'scope_count' => Bt03ProductionContract::SCOPE_COUNT,
                'unseen_scope_count' => $unseenScopes,
                'completed_at' => $now->format(DATE_ATOM),
            ];
            $locked->forceFill([
                'status' => 'SUCCEEDED',
                'parameters' => $parameters,
                'error_count' => 0,
                'error_summary' => null,
                'finished_at' => $now,
            ])->save();
        });

        return new Bt03ProductionSummaryDto(
            (int) $run->id,
            (string) $run->run_uuid,
            Bt03ProductionContract::SCOPE_COUNT,
            Bt03ProductionContract::SCOPE_COUNT,
            $skippedScopeCount,
            $effectCount,
            $unseenScopes,
            $runManifest,
        );
    }

    public function markRunFailure(
        BacktestRun $run,
        Throwable $primary,
        bool $resumeAllowed,
        ?string $resumeBlockReason,
        ?Throwable $diagnosticFailure,
    ): void {
        DB::transaction(function () use ($run, $primary, $resumeAllowed, $resumeBlockReason, $diagnosticFailure): void {
            $locked = BacktestRun::query()->lockForUpdate()->findOrFail($run->id);
            $scopes = $this->scopes($locked);
            $completed = count(array_filter($scopes, fn (BacktestBinEffectScope $scope): bool => $scope->status === 'SUCCEEDED'));
            $parameters = $this->parameters($locked);
            $parameters['runtime']['completed_scope_count'] = $completed;
            $parameters['runtime']['resume_allowed'] = $resumeAllowed;
            $parameters['runtime']['resume_block_reason'] = $resumeBlockReason;
            $parameters['runtime']['last_failure'] = [
                'primary' => $this->error($primary),
                'preflight_diagnostic' => $diagnosticFailure === null ? null : $this->error($diagnosticFailure),
                'failed_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            ];
            $now = new DateTimeImmutable('now');
            $locked->forceFill([
                'status' => $completed === 0 ? 'FAILED' : 'PARTIALLY_SUCCEEDED',
                'parameters' => $parameters,
                'error_count' => 1,
                'error_summary' => $this->error($primary),
                'finished_at' => $now,
            ])->save();
            $this->finalizeFailedFolds($locked, $now);
        });
    }

    private function assertRunContract(BacktestRun $run, Bt03PreflightSummaryDto $preflight): void
    {
        $this->assertResumeAllowed((int) $run->id);
        $parameters = $this->parameters($run);
        $contract = $parameters['contract'] ?? null;
        if (! is_array($contract)
            || ($contract['source_bt02_run_id'] ?? null) !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
            || ($contract['source_bt02_run_uuid'] ?? null) !== Bt03SourceManifest::SOURCE_BT02_RUN_UUID
            || ($contract['source_bt02_manifest_hash'] ?? null) !== Bt03SourceManifest::SOURCE_BT02_MANIFEST_HASH
            || ($contract['bt03_source_manifest_hash'] ?? null) !== Bt03SourceManifest::HASH
            || ($contract['source_artifact_manifest_hash'] ?? null) !== $preflight->source->fingerprints->manifestHash
            || ($contract['outcome_snapshot_manifest_hash'] ?? null) !== Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH
            || ($contract['fixed_bin_contract'] ?? null) !== Bt03SourceManifest::FIXED_BIN_CONTRACT
            || ($contract['probability_semantics'] ?? null) !== Bt03SourceManifest::PROBABILITY_SEMANTICS
            || ($contract['bootstrap_iterations'] ?? null) !== Bt03ProductionContract::BOOTSTRAP_ITERATIONS
            || ($contract['bootstrap_seed'] ?? null) !== Bt03ProductionContract::BOOTSTRAP_SEED
            || ($contract['folds'] ?? null) !== Bt03ProductionContract::FOLDS
            || ($contract['stat_codes'] ?? null) !== Bt03SourceManifest::ENTRY_STAT_CODES
            || ($contract['cohorts'] ?? null) !== Bt03ProductionContract::COHORTS
            || ($contract['labels'] ?? null) !== Bt03ProductionContract::LABELS
            || ($contract['scope_count'] ?? null) !== Bt03ProductionContract::SCOPE_COUNT
            || ($contract['base_effect_count'] ?? null) !== Bt03ProductionContract::BASE_EFFECT_COUNT
            || ($contract['race_count_semantics'] ?? null) !== Bt03ProductionContract::RACE_COUNT_SEMANTICS
            || ($contract['2026_blocked'] ?? null) !== true) {
            throw new RuntimeException('BT-03 Production resume contract was invalid.');
        }
    }

    private function assertTargetStructure(BacktestRun $run): void
    {
        $sourceFolds = $this->sourceFolds();
        $sourceSpecs = $this->sourceSpecs();
        $targetFolds = BacktestFold::query()->where('backtest_run_id', $run->id)->get()->keyBy('fold_code');
        $targetSpecs = BacktestSignalSpec::query()->where('backtest_run_id', $run->id)->get()->keyBy('stat_code');
        if ($targetFolds->count() !== count(Bt03ProductionContract::FOLDS)
            || $targetSpecs->count() !== count(Bt03SourceManifest::ENTRY_STAT_CODES)) {
            throw new RuntimeException('BT-03 Production target folds or specs were incomplete.');
        }
        foreach ($sourceFolds as $foldCode => $source) {
            $target = $targetFolds->get($foldCode);
            if (! $target instanceof BacktestFold
                || (int) $target->sequence !== (int) $source->sequence
                || $target->train_from?->format('Y-m-d') !== (string) $source->train_from
                || $target->train_to?->format('Y-m-d') !== (string) $source->train_to
                || $target->evaluation_from->format('Y-m-d') !== (string) $source->evaluation_from
                || $target->evaluation_to->format('Y-m-d') !== (string) $source->evaluation_to) {
                throw new RuntimeException('BT-03 Production target fold differed from its fixed source.');
            }
        }
        foreach ($sourceSpecs as $statCode => $source) {
            $target = $targetSpecs->get($statCode);
            if (! $target instanceof BacktestSignalSpec
                || $target->analysis_role !== 'ENTRY_INCREMENTAL'
                || $target->primary_feature_code !== $source->primary_feature_code
                || ($target->parameters['source_bt02_signal_spec_id'] ?? null) !== (int) $source->id) {
                throw new RuntimeException('BT-03 Production target signal spec differed from its fixed source.');
            }
        }
        foreach ($this->scopes($run) as $index => $scope) {
            $definition = $this->contract->scopes()[$index];
            [$count, $hash] = $this->sourceBinIdentity(
                (int) $scope->source_backtest_fold_id,
                (int) $scope->source_backtest_signal_spec_id,
                (string) $scope->cohort_code,
            );
            if ((int) $scope->source_backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
                || (int) $scope->expected_training_bin_count !== $count
                || ! hash_equals((string) $scope->source_boundaries_hash, $hash)
                || (int) $scope->bootstrap_iterations !== Bt03ProductionContract::BOOTSTRAP_ITERATIONS
                || (int) $scope->bootstrap_seed !== Bt03ProductionContract::BOOTSTRAP_SEED
                || $scope->fold_code !== $definition->foldCode
                || $scope->stat_code !== $definition->statCode
                || $scope->cohort_code !== $definition->cohortCode) {
                throw new RuntimeException('BT-03 Production scope ledger differed from the fixed source.');
            }
            if ($scope->status !== 'SUCCEEDED' && $this->effectCount($scope) !== 0) {
                throw new RuntimeException('BT-03 Production incomplete scope contained partial effects.');
            }
        }
    }

    /** @param list<Bt03ComputedBinEffectDto> $effects */
    private function assertSourceOwnership(BacktestBinEffectScope $scope, array $effects): void
    {
        $modelIds = [];
        $binIds = [];
        foreach ($effects as $effect) {
            $modelIds[] = $effect->models->baseline->modelId;
            $modelIds[] = $effect->models->incremental->modelId;
            if ($effect->bin->sourceEffectBinId !== null) {
                $binIds[] = $effect->bin->sourceEffectBinId;
            }
        }
        $models = DB::table('backtest_models')->whereIn('id', array_unique($modelIds))->get()->keyBy('id');
        $bins = DB::table('backtest_effect_bins')->whereIn('id', array_unique($binIds))->get()->keyBy('id');
        foreach ($effects as $effect) {
            foreach ([
                'BASELINE_MATCHED' => $effect->models->baseline,
                'INCREMENTAL' => $effect->models->incremental,
            ] as $role => $model) {
                $row = $models->get($model->modelId);
                if ($row === null
                    || (int) $row->backtest_run_id !== (int) $scope->source_backtest_run_id
                    || (int) $row->backtest_fold_id !== (int) $scope->source_backtest_fold_id
                    || (int) $row->backtest_signal_spec_id !== (int) $scope->source_backtest_signal_spec_id
                    || $row->cohort_code !== $scope->cohort_code
                    || $row->label_code !== $effect->labelCode
                    || $row->model_role !== $role
                    || $row->model_hash !== $model->modelHash) {
                    throw new RuntimeException('BT-03 Production persisted source model ownership was invalid.');
                }
            }
            if ($effect->bin->sourceEffectBinId !== null) {
                $bin = $bins->get($effect->bin->sourceEffectBinId);
                if ($bin === null
                    || (int) $bin->backtest_run_id !== (int) $scope->source_backtest_run_id
                    || (int) $bin->backtest_fold_id !== (int) $scope->source_backtest_fold_id
                    || (int) $bin->backtest_signal_spec_id !== (int) $scope->source_backtest_signal_spec_id
                    || $bin->cohort_code !== $scope->cohort_code
                    || (int) $bin->bin_index !== $effect->bin->binIndex
                    || $bin->boundaries_hash !== $effect->bin->boundariesHash) {
                    throw new RuntimeException('BT-03 Production persisted source bin ownership was invalid.');
                }
            }
        }
    }

    /**
     * @param  list<Bt03ComputedBinEffectDto>|null  $expected
     * @return list<object>
     */
    private function verifyPersistedScope(
        BacktestBinEffectScope $scope,
        string $foldCode,
        string $statCode,
        ?array $expected,
        string $expectedManifest,
    ): array {
        $rows = DB::table('backtest_bin_effects')
            ->where('backtest_run_id', $scope->backtest_run_id)
            ->where('backtest_fold_id', $scope->backtest_fold_id)
            ->where('backtest_signal_spec_id', $scope->backtest_signal_spec_id)
            ->where('cohort_code', $scope->cohort_code)
            ->orderBy('label_code')
            ->orderBy('bin_index')
            ->get()->all();
        $expectedCount = ((int) $scope->expected_training_bin_count * Bt03ProductionContract::LABEL_COUNT)
            + ((int) $scope->unseen_row_count > 0 || ($expected !== null && $this->computedHasUnseen($expected))
                ? Bt03ProductionContract::LABEL_COUNT
                : 0);
        if (count($rows) !== $expectedCount) {
            throw new RuntimeException('BT-03 Production persisted effect row count was invalid.');
        }
        $expectedByKey = $expected === null ? [] : collect($expected)->keyBy(
            fn (Bt03ComputedBinEffectDto $effect): string => $effect->labelCode.':'.$effect->bin->binIndex,
        )->all();
        $models = DB::table('backtest_models')->whereIn('id', array_unique(array_merge(
            array_map(fn (object $row): int => (int) $row->source_baseline_model_id, $rows),
            array_map(fn (object $row): int => (int) $row->source_incremental_model_id, $rows),
        )))->get()->keyBy('id');
        $liveBins = DB::table('backtest_effect_bins')
            ->where('backtest_run_id', $scope->source_backtest_run_id)
            ->where('backtest_fold_id', $scope->source_backtest_fold_id)
            ->where('backtest_signal_spec_id', $scope->source_backtest_signal_spec_id)
            ->where('cohort_code', $scope->cohort_code)
            ->get()->keyBy('id');
        $seen = [];
        foreach ($rows as $row) {
            $key = $row->label_code.':'.(int) $row->bin_index;
            if (isset($seen[$key])
                || ! in_array($row->label_code, Bt03ProductionContract::LABELS, true)
                || (int) $row->bootstrap_iterations !== Bt03ProductionContract::BOOTSTRAP_ITERATIONS
                || (int) $row->bootstrap_seed !== Bt03ProductionContract::BOOTSTRAP_SEED
                || (int) $row->source_backtest_run_id !== (int) $scope->source_backtest_run_id
                || (int) $row->source_backtest_fold_id !== (int) $scope->source_backtest_fold_id
                || $row->boundaries_hash !== $scope->source_boundaries_hash) {
                throw new RuntimeException('BT-03 Production persisted effect identity was invalid.');
            }
            $seen[$key] = true;
            $baseline = $models->get((int) $row->source_baseline_model_id);
            $incremental = $models->get((int) $row->source_incremental_model_id);
            if ($baseline === null || $incremental === null
                || $baseline->model_role !== 'BASELINE_MATCHED'
                || $incremental->model_role !== 'INCREMENTAL'
                || $baseline->label_code !== $row->label_code
                || $incremental->label_code !== $row->label_code
                || $baseline->cohort_code !== $scope->cohort_code
                || $incremental->cohort_code !== $scope->cohort_code
                || (int) $baseline->backtest_fold_id !== (int) $scope->source_backtest_fold_id
                || (int) $incremental->backtest_fold_id !== (int) $scope->source_backtest_fold_id
                || (int) $baseline->backtest_signal_spec_id !== (int) $scope->source_backtest_signal_spec_id
                || (int) $incremental->backtest_signal_spec_id !== (int) $scope->source_backtest_signal_spec_id) {
                throw new RuntimeException('BT-03 Production persisted effect model ownership was invalid.');
            }
            if ($row->bin_origin === 'TRAINING_BIN') {
                $bin = $liveBins->get((int) $row->source_backtest_effect_bin_id);
                if ($bin === null || (int) $bin->bin_index !== (int) $row->bin_index
                    || $bin->boundaries_hash !== $row->boundaries_hash) {
                    throw new RuntimeException('BT-03 Production persisted effect bin ownership was invalid.');
                }
            } elseif ($row->bin_origin !== 'UNSEEN_CATEGORY'
                || $row->source_backtest_effect_bin_id !== null
                || (int) $row->bin_index !== 0) {
                throw new RuntimeException('BT-03 Production persisted UNSEEN effect was invalid.');
            }
            $actualHash = $this->effectHasher->hash($this->effectArtifact($row, $baseline, $incremental));
            if (! hash_equals((string) $row->effect_hash, $actualHash)
                || (isset($expectedByKey[$key]) && ! hash_equals($expectedByKey[$key]->effectHash, $actualHash))) {
                throw new RuntimeException('BT-03 Production persisted effect hash mismatched after round-trip.');
            }
        }
        $manifest = $this->manifests->fromPersisted($scope, $foldCode, $statCode, $rows);
        if (! hash_equals($expectedManifest, $manifest)) {
            throw new RuntimeException('BT-03 Production persisted scope effect manifest mismatched.');
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function effectRow(BacktestBinEffectScope $scope, Bt03ComputedBinEffectDto $effect, DateTimeImmutable $now): array
    {
        $result = $effect->result;

        return [
            'backtest_run_id' => $scope->backtest_run_id,
            'backtest_fold_id' => $scope->backtest_fold_id,
            'backtest_signal_spec_id' => $scope->backtest_signal_spec_id,
            'source_backtest_run_id' => $scope->source_backtest_run_id,
            'source_backtest_fold_id' => $scope->source_backtest_fold_id,
            'source_baseline_model_id' => $effect->models->baseline->modelId,
            'source_incremental_model_id' => $effect->models->incremental->modelId,
            'source_backtest_effect_bin_id' => $effect->bin->sourceEffectBinId,
            'cohort_code' => $scope->cohort_code,
            'label_code' => $effect->labelCode,
            'bin_index' => $effect->bin->binIndex,
            'bin_origin' => $effect->bin->binOrigin,
            'bin_kind' => $effect->bin->binKind,
            'lower_bound' => $this->canonicalFloat($effect->bin->lowerBound),
            'upper_bound' => $this->canonicalFloat($effect->bin->upperBound),
            'category_value' => $effect->bin->categoryValue,
            'training_sample_count' => $effect->bin->trainingSampleCount,
            'evaluation_status' => $result->evaluationStatus,
            'evaluation_sample_count' => $result->evaluationSampleCount,
            'evaluation_race_count' => $result->evaluationRaceCount,
            'positive_count' => $result->positiveCount,
            'observed_rate' => $this->canonicalFloat($result->observedRate),
            'observed_rate_ci_lower' => $this->canonicalFloat($result->observedRateCiLower),
            'observed_rate_ci_upper' => $this->canonicalFloat($result->observedRateCiUpper),
            'baseline_mean_probability' => $this->canonicalFloat($result->baselineMeanProbability),
            'incremental_mean_probability' => $this->canonicalFloat($result->incrementalMeanProbability),
            'baseline_residual_mean' => $this->canonicalFloat($result->baselineResidualMean),
            'baseline_residual_ci_lower' => $this->canonicalFloat($result->baselineResidualCiLower),
            'baseline_residual_ci_upper' => $this->canonicalFloat($result->baselineResidualCiUpper),
            'incremental_residual_mean' => $this->canonicalFloat($result->incrementalResidualMean),
            'incremental_residual_ci_lower' => $this->canonicalFloat($result->incrementalResidualCiLower),
            'incremental_residual_ci_upper' => $this->canonicalFloat($result->incrementalResidualCiUpper),
            'probability_shift_mean' => $this->canonicalFloat($result->probabilityShiftMean),
            'probability_shift_ci_lower' => $this->canonicalFloat($result->probabilityShiftCiLower),
            'probability_shift_ci_upper' => $this->canonicalFloat($result->probabilityShiftCiUpper),
            'log_loss_delta' => $this->canonicalFloat($result->logLossDelta),
            'log_loss_delta_ci_lower' => $this->canonicalFloat($result->logLossDeltaCiLower),
            'log_loss_delta_ci_upper' => $this->canonicalFloat($result->logLossDeltaCiUpper),
            'brier_delta' => $this->canonicalFloat($result->brierDelta),
            'brier_delta_ci_lower' => $this->canonicalFloat($result->brierDeltaCiLower),
            'brier_delta_ci_upper' => $this->canonicalFloat($result->brierDeltaCiUpper),
            'bootstrap_iterations' => $result->bootstrapIterations,
            'bootstrap_seed' => $result->bootstrapSeed,
            'boundaries_hash' => $effect->bin->boundariesHash,
            'effect_hash' => $effect->effectHash,
            'calculated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string, mixed> */
    private function effectArtifact(object $row, object $baseline, object $incremental): array
    {
        return [
            'source_bt02_run_id' => (int) $row->source_backtest_run_id,
            'source_bt02_run_uuid' => Bt03SourceManifest::SOURCE_BT02_RUN_UUID,
            'source_fold_id' => (int) $row->source_backtest_fold_id,
            'source_signal_spec_id' => (int) $baseline->backtest_signal_spec_id,
            'source_baseline_model_hash' => (string) $baseline->model_hash,
            'source_incremental_model_hash' => (string) $incremental->model_hash,
            'source_boundaries_hash' => (string) $row->boundaries_hash,
            'source_backtest_effect_bin_id' => $row->source_backtest_effect_bin_id === null ? null : (int) $row->source_backtest_effect_bin_id,
            'cohort_code' => (string) $row->cohort_code,
            'label_code' => (string) $row->label_code,
            'bin_index' => (int) $row->bin_index,
            'bin_origin' => (string) $row->bin_origin,
            'bin_kind' => (string) $row->bin_kind,
            'lower_bound' => $this->nullableFloat($row->lower_bound),
            'upper_bound' => $this->nullableFloat($row->upper_bound),
            'category_value' => $row->category_value === null ? null : (string) $row->category_value,
            'training_sample_count' => (int) $row->training_sample_count,
            'evaluation_status' => (string) $row->evaluation_status,
            'evaluation_sample_count' => (int) $row->evaluation_sample_count,
            'evaluation_race_count' => (int) $row->evaluation_race_count,
            'positive_count' => (int) $row->positive_count,
            'observed_rate' => $this->nullableFloat($row->observed_rate),
            'observed_rate_ci_lower' => $this->nullableFloat($row->observed_rate_ci_lower),
            'observed_rate_ci_upper' => $this->nullableFloat($row->observed_rate_ci_upper),
            'baseline_mean_probability' => $this->nullableFloat($row->baseline_mean_probability),
            'incremental_mean_probability' => $this->nullableFloat($row->incremental_mean_probability),
            'baseline_residual_mean' => $this->nullableFloat($row->baseline_residual_mean),
            'baseline_residual_ci_lower' => $this->nullableFloat($row->baseline_residual_ci_lower),
            'baseline_residual_ci_upper' => $this->nullableFloat($row->baseline_residual_ci_upper),
            'incremental_residual_mean' => $this->nullableFloat($row->incremental_residual_mean),
            'incremental_residual_ci_lower' => $this->nullableFloat($row->incremental_residual_ci_lower),
            'incremental_residual_ci_upper' => $this->nullableFloat($row->incremental_residual_ci_upper),
            'probability_shift_mean' => $this->nullableFloat($row->probability_shift_mean),
            'probability_shift_ci_lower' => $this->nullableFloat($row->probability_shift_ci_lower),
            'probability_shift_ci_upper' => $this->nullableFloat($row->probability_shift_ci_upper),
            'log_loss_delta' => $this->nullableFloat($row->log_loss_delta),
            'log_loss_delta_ci_lower' => $this->nullableFloat($row->log_loss_delta_ci_lower),
            'log_loss_delta_ci_upper' => $this->nullableFloat($row->log_loss_delta_ci_upper),
            'brier_delta' => $this->nullableFloat($row->brier_delta),
            'brier_delta_ci_lower' => $this->nullableFloat($row->brier_delta_ci_lower),
            'brier_delta_ci_upper' => $this->nullableFloat($row->brier_delta_ci_upper),
            'bootstrap_iterations' => (int) $row->bootstrap_iterations,
            'bootstrap_seed' => (int) $row->bootstrap_seed,
            'calculation_version' => Bt03BinEffectCalculator::CALCULATION_VERSION,
        ];
    }

    /** @return array<string, mixed> */
    private function initialParameters(Bt03PreflightSummaryDto $preflight): array
    {
        return [
            'contract' => [
                'source_bt02_run_id' => Bt03SourceManifest::SOURCE_BT02_RUN_ID,
                'source_bt02_run_uuid' => Bt03SourceManifest::SOURCE_BT02_RUN_UUID,
                'source_bt02_manifest_hash' => Bt03SourceManifest::SOURCE_BT02_MANIFEST_HASH,
                'bt03_source_manifest_hash' => Bt03SourceManifest::HASH,
                'source_artifact_manifest_hash' => $preflight->source->fingerprints->manifestHash,
                'outcome_snapshot_manifest_hash' => Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH,
                'fixed_bin_contract' => Bt03SourceManifest::FIXED_BIN_CONTRACT,
                'probability_semantics' => Bt03SourceManifest::PROBABILITY_SEMANTICS,
                'bootstrap_iterations' => Bt03ProductionContract::BOOTSTRAP_ITERATIONS,
                'bootstrap_seed' => Bt03ProductionContract::BOOTSTRAP_SEED,
                'folds' => Bt03ProductionContract::FOLDS,
                'stat_codes' => Bt03SourceManifest::ENTRY_STAT_CODES,
                'cohorts' => Bt03ProductionContract::COHORTS,
                'labels' => Bt03ProductionContract::LABELS,
                'scope_count' => Bt03ProductionContract::SCOPE_COUNT,
                'base_effect_count' => Bt03ProductionContract::BASE_EFFECT_COUNT,
                'race_count_semantics' => Bt03ProductionContract::RACE_COUNT_SEMANTICS,
                '2026_blocked' => true,
            ],
            'runtime' => [
                'execution_attempt' => 1,
                'resume_count' => 0,
                'resume_allowed' => true,
                'resume_block_reason' => null,
                'completed_scope_count' => 0,
                'last_scope' => null,
                'last_failure' => null,
            ],
            'result' => null,
        ];
    }

    /** @return array<string, object> */
    private function sourceFolds(): array
    {
        $rows = DB::table('backtest_folds')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->whereIn('fold_code', Bt03ProductionContract::FOLDS)
            ->get()->keyBy('fold_code');
        $folds = [];
        foreach (Bt03ProductionContract::FOLDS as $code) {
            $fold = $rows->get($code);
            if ($fold === null || $fold->status !== 'SUCCEEDED') {
                throw new RuntimeException('BT-03 Production fixed source fold was unavailable.');
            }
            $folds[$code] = $fold;
        }
        if ($rows->count() !== count(Bt03ProductionContract::FOLDS)) {
            throw new RuntimeException('BT-03 Production fixed source folds exceeded the contract.');
        }

        return $folds;
    }

    /** @return array<string, object> */
    private function sourceSpecs(): array
    {
        $rows = DB::table('backtest_signal_specs')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->whereIn('stat_code', Bt03SourceManifest::ENTRY_STAT_CODES)
            ->get()->keyBy('stat_code');
        $specs = [];
        foreach (Bt03SourceManifest::ENTRY_STAT_CODES as $code) {
            $spec = $rows->get($code);
            if ($spec === null || $spec->analysis_role !== 'ENTRY_INCREMENTAL') {
                throw new RuntimeException('BT-03 Production fixed source signal spec was unavailable.');
            }
            $specs[$code] = $spec;
        }
        if ($rows->count() !== count(Bt03SourceManifest::ENTRY_STAT_CODES)) {
            throw new RuntimeException('BT-03 Production fixed source specs exceeded the contract.');
        }

        return $specs;
    }

    private function sourceRun(): object
    {
        $run = DB::table('backtest_runs')->where('id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)->first();
        if ($run === null || $run->run_uuid !== Bt03SourceManifest::SOURCE_BT02_RUN_UUID
            || $run->backtest_code !== 'BT-02' || $run->status !== 'SUCCEEDED') {
            throw new RuntimeException('BT-03 Production fixed source run was unavailable.');
        }

        return $run;
    }

    /** @return array{int, string} */
    private function sourceBinIdentity(int $foldId, int $specId, string $cohort): array
    {
        $rows = DB::table('backtest_effect_bins')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->where('backtest_fold_id', $foldId)
            ->where('backtest_signal_spec_id', $specId)
            ->where('cohort_code', $cohort)
            ->orderBy('bin_index')
            ->get();
        $hashes = $rows->pluck('boundaries_hash')->unique()->values();
        if ($rows->isEmpty() || $hashes->count() !== 1
            || ! is_string($hashes->first())
            || preg_match('/\A[0-9a-f]{64}\z/', $hashes->first()) !== 1) {
            throw new RuntimeException('BT-03 Production source bin identity was invalid.');
        }

        return [$rows->count(), $hashes->first()];
    }

    private function effectCount(BacktestBinEffectScope $scope): int
    {
        return DB::table('backtest_bin_effects')
            ->where('backtest_run_id', $scope->backtest_run_id)
            ->where('backtest_fold_id', $scope->backtest_fold_id)
            ->where('backtest_signal_spec_id', $scope->backtest_signal_spec_id)
            ->where('cohort_code', $scope->cohort_code)
            ->count();
    }

    private function finalizeFailedFolds(BacktestRun $run, DateTimeImmutable $now): void
    {
        foreach (BacktestFold::query()->where('backtest_run_id', $run->id)->get() as $fold) {
            $statuses = BacktestBinEffectScope::query()
                ->where('backtest_run_id', $run->id)
                ->where('backtest_fold_id', $fold->id)
                ->pluck('status');
            $succeeded = $statuses->filter(fn (string $status): bool => $status === 'SUCCEEDED')->count();
            $failed = $statuses->filter(fn (string $status): bool => $status === 'FAILED')->count();
            $status = $succeeded === 24 ? 'SUCCEEDED'
                : ($succeeded > 0 ? 'PARTIALLY_SUCCEEDED' : ($failed > 0 ? 'FAILED' : 'RUNNING'));
            $fold->forceFill([
                'status' => $status,
                'finished_at' => $status === 'RUNNING' ? null : $now,
            ])->save();
        }
    }

    private function updateProgress(BacktestBinEffectScope $scope, string $foldCode, string $statCode, DateTimeImmutable $now): void
    {
        $run = BacktestRun::query()->lockForUpdate()->findOrFail($scope->backtest_run_id);
        $parameters = $this->parameters($run);
        $parameters['runtime']['completed_scope_count'] = BacktestBinEffectScope::query()
            ->where('backtest_run_id', $run->id)
            ->where('status', 'SUCCEEDED')
            ->count();
        $parameters['runtime']['last_scope'] = [
            'fold' => $foldCode,
            'stat' => $statCode,
            'cohort' => (string) $scope->cohort_code,
            'status' => 'SUCCEEDED',
            'finished_at' => $now->format(DATE_ATOM),
        ];
        $run->forceFill(['parameters' => $parameters])->save();

        $succeeded = BacktestBinEffectScope::query()
            ->where('backtest_run_id', $run->id)
            ->where('backtest_fold_id', $scope->backtest_fold_id)
            ->where('status', 'SUCCEEDED')
            ->count();
        if ($succeeded === 24) {
            BacktestFold::query()->where('id', $scope->backtest_fold_id)->update([
                'status' => 'SUCCEEDED',
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function parameters(BacktestRun $run): array
    {
        $parameters = $run->parameters;
        if (! is_array($parameters)
            || ! is_array($parameters['runtime'] ?? null)) {
            throw new RuntimeException('BT-03 Production run parameters were invalid.');
        }

        return $parameters;
    }

    /** @return array<mixed> */
    private function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($decoded)) {
            throw new RuntimeException('BT-03 Production source JSON was invalid.');
        }

        return $decoded;
    }

    private function canonicalFloat(?float $value): ?string
    {
        return $value === null ? null : sprintf('%.17g', $value);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $float = (float) $value;
        if (! is_finite($float)) {
            throw new RuntimeException('BT-03 Production persisted float was invalid.');
        }

        return $float;
    }

    /** @param list<Bt03ComputedBinEffectDto> $effects */
    private function computedHasUnseen(array $effects): bool
    {
        return count(array_filter(
            $effects,
            fn (Bt03ComputedBinEffectDto $effect): bool => $effect->bin->binOrigin === 'UNSEEN_CATEGORY',
        )) > 0;
    }

    private function scopeKey(string $fold, string $stat, string $cohort): string
    {
        return "{$fold}:{$stat}:{$cohort}";
    }

    private function error(Throwable $failure): string
    {
        return mb_substr($failure::class.': '.$failure->getMessage(), 0, 10000);
    }
}
