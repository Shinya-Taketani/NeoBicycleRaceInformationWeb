<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use App\Domain\Keirin\Backtest\Services\Bt03EffectManifestService;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Models\BacktestBinEffectScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Bt03eRuleSourceRepository
{
    public function __construct(
        private readonly Bt03EffectManifestService $manifests,
        private readonly Bt02ModelArtifactHasher $hasher,
    ) {}

    /** @return array<string, int|string> */
    public function auditState(): array
    {
        $run = DB::table('backtest_runs')->where('id', Bt03eContract::SOURCE_RUN_ID)->first()
            ?? throw new RuntimeException('BT-03E fixed source run 6 was unavailable.');

        $parameters = $this->jsonObject($run->parameters ?? null);
        $selectedScopes = DB::table('backtest_bin_effect_scopes as scope')
            ->join('backtest_folds as fold', 'fold.id', '=', 'scope.backtest_fold_id')
            ->join('backtest_signal_specs as spec', 'spec.id', '=', 'scope.backtest_signal_spec_id')
            ->where('scope.backtest_run_id', Bt03eContract::SOURCE_RUN_ID)
            ->where('fold.fold_code', Bt03eContract::SOURCE_FOLD)
            ->where('scope.cohort_code', Bt03eContract::COHORT)
            ->whereIn('spec.stat_code', Bt03eContract::STAT_CODES)
            ->orderBy('spec.stat_code')
            ->get(['spec.stat_code', 'scope.effect_count', 'scope.effect_manifest_hash', 'scope.source_boundaries_hash']);

        return [
            'run_id' => (int) $run->id,
            'run_uuid' => (string) $run->run_uuid,
            'backtest_code' => (string) $run->backtest_code,
            'status' => (string) $run->status,
            'error_count' => (int) $run->error_count,
            'parameters_sha256' => hash('sha256', (string) $run->parameters),
            'effect_manifest_hash' => (string) ($parameters['result']['effect_manifest_hash'] ?? ''),
            'updated_at' => (string) $run->updated_at,
            'scope_count' => DB::table('backtest_bin_effect_scopes')->where('backtest_run_id', Bt03eContract::SOURCE_RUN_ID)->count(),
            'effect_count' => DB::table('backtest_bin_effects')->where('backtest_run_id', Bt03eContract::SOURCE_RUN_ID)->count(),
            'selected_scope_count' => $selectedScopes->count(),
            'selected_effect_count' => (int) $selectedScopes->sum('effect_count'),
            'selected_scope_manifest_digest' => $this->hasher->hash($selectedScopes->map(static fn (object $scope): array => [
                'stat_code' => (string) $scope->stat_code,
                'effect_count' => (int) $scope->effect_count,
                'effect_manifest_hash' => (string) $scope->effect_manifest_hash,
                'source_boundaries_hash' => (string) $scope->source_boundaries_hash,
            ])->all()),
        ];
    }

    /** @return array{audit: array<string, int|string>, semantic_digest: string, used_effect_row_count: int, rows: list<object>} */
    public function sourceSnapshot(): array
    {
        $audit = $this->auditState();
        $rows = $this->rows();
        if ($audit['selected_scope_count'] !== Bt03eContract::SOURCE_SCOPE_COUNT
            || $audit['selected_effect_count'] !== Bt03eContract::SOURCE_EFFECT_ROW_COUNT
            || count($rows) !== Bt03eContract::SOURCE_EFFECT_ROW_COUNT
            || $audit['effect_manifest_hash'] !== Bt03eContract::EFFECT_MANIFEST_HASH) {
            throw new RuntimeException('BT-03E selected run 6 source contract was incomplete.');
        }

        return [
            'audit' => $audit,
            'semantic_digest' => $this->semanticDigest($rows),
            'used_effect_row_count' => count($rows),
            'rows' => $rows,
        ];
    }

    public function outcomeSnapshotPath(): string
    {
        $sourceRun = DB::table('backtest_runs')->where('id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)->first()
            ?? throw new RuntimeException('BT-03E fixed BT-02 source run was unavailable.');
        $parameters = $this->jsonObject($sourceRun->parameters ?? null);
        $path = $parameters['outcome_snapshot_path'] ?? null;
        if (($sourceRun->run_uuid ?? null) !== Bt03SourceManifest::SOURCE_BT02_RUN_UUID
            || ($sourceRun->status ?? null) !== 'SUCCEEDED'
            || ($parameters['outcome_snapshot_manifest_hash'] ?? null) !== Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH
            || ! is_string($path)
            || ! str_ends_with($path, '/'.Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH)) {
            throw new RuntimeException('BT-03E fixed outcome snapshot source identity was invalid.');
        }

        return $path;
    }

    /** @return list<object> */
    public function rows(): array
    {
        $run = DB::table('backtest_runs')->where('id', Bt03eContract::SOURCE_RUN_ID)->first()
            ?? throw new RuntimeException('BT-03E fixed source run 6 was unavailable.');
        $parameters = $this->jsonObject($run->parameters ?? null);
        if (($run->backtest_code ?? null) !== 'BT-03'
            || ($run->status ?? null) !== 'SUCCEEDED'
            || (int) ($run->error_count ?? -1) !== 0
            || ($parameters['result']['scope_count'] ?? null) !== 72
            || ($parameters['result']['effect_count'] ?? null) !== 2004
            || ($parameters['result']['effect_manifest_hash'] ?? null) !== Bt03eContract::EFFECT_MANIFEST_HASH
            || DB::table('backtest_bin_effect_scopes')->where('backtest_run_id', Bt03eContract::SOURCE_RUN_ID)->count() !== 72
            || DB::table('backtest_bin_effects')->where('backtest_run_id', Bt03eContract::SOURCE_RUN_ID)->count() !== 2004) {
            throw new RuntimeException('BT-03E fixed source run 6 identity or completeness was invalid.');
        }

        $scopes = DB::table('backtest_bin_effect_scopes as scope')
            ->join('backtest_folds as fold', 'fold.id', '=', 'scope.backtest_fold_id')
            ->join('backtest_signal_specs as spec', 'spec.id', '=', 'scope.backtest_signal_spec_id')
            ->where('scope.backtest_run_id', Bt03eContract::SOURCE_RUN_ID)
            ->where('fold.fold_code', Bt03eContract::SOURCE_FOLD)
            ->where('scope.cohort_code', Bt03eContract::COHORT)
            ->orderBy('spec.stat_code')
            ->get([
                'scope.id', 'scope.status', 'scope.effect_count', 'scope.expected_training_bin_count',
                'scope.source_backtest_run_id', 'scope.source_backtest_fold_id',
                'scope.source_backtest_signal_spec_id', 'scope.cohort_code',
                'scope.bootstrap_iterations', 'scope.bootstrap_seed',
                'scope.source_boundaries_hash', 'scope.effect_manifest_hash', 'spec.stat_code',
            ]);
        if ($scopes->count() !== count(Bt03eContract::STAT_CODES)
            || $scopes->pluck('stat_code')->all() !== Bt03eContract::STAT_CODES) {
            throw new RuntimeException('BT-03E required exactly the 12 WF_2023 operational scopes.');
        }
        foreach ($scopes as $scope) {
            if ($scope->status !== 'SUCCEEDED'
                || (int) $scope->effect_count !== (int) $scope->expected_training_bin_count * 3
                || preg_match('/\A[0-9a-f]{64}\z/', (string) $scope->source_boundaries_hash) !== 1
                || preg_match('/\A[0-9a-f]{64}\z/', (string) $scope->effect_manifest_hash) !== 1) {
                throw new RuntimeException('BT-03E source scope was incomplete or invalid.');
            }
        }

        $rows = DB::table('backtest_bin_effects as effect')
            ->join('backtest_folds as fold', 'fold.id', '=', 'effect.backtest_fold_id')
            ->join('backtest_signal_specs as spec', 'spec.id', '=', 'effect.backtest_signal_spec_id')
            ->where('effect.backtest_run_id', Bt03eContract::SOURCE_RUN_ID)
            ->where('fold.fold_code', Bt03eContract::SOURCE_FOLD)
            ->where('effect.cohort_code', Bt03eContract::COHORT)
            ->whereIn('spec.stat_code', Bt03eContract::STAT_CODES)
            ->orderBy('spec.stat_code')
            ->orderBy('effect.bin_index')
            ->orderBy('effect.label_code')
            ->get([
                'spec.stat_code', 'effect.label_code', 'effect.bin_index', 'effect.bin_origin',
                'effect.bin_kind', 'effect.lower_bound', 'effect.upper_bound', 'effect.category_value',
                'effect.source_backtest_effect_bin_id', 'effect.boundaries_hash', 'effect.training_sample_count',
                'effect.evaluation_status', 'effect.centered_ci_status',
                'effect.centered_baseline_residual_mean', 'effect.centered_baseline_residual_ci_lower',
                'effect.centered_baseline_residual_ci_upper', 'effect.effect_hash',
            ])->all();
        if (count($rows) !== (int) $scopes->sum('effect_count')) {
            throw new RuntimeException('BT-03E source effect rows did not match the fixed scopes.');
        }
        $scopeByStat = $scopes->keyBy('stat_code');
        $rowCounts = [];
        foreach ($rows as $row) {
            $scope = $scopeByStat->get($row->stat_code);
            $rowCounts[$row->stat_code] = ($rowCounts[$row->stat_code] ?? 0) + 1;
            if ($scope === null || ! hash_equals((string) $scope->source_boundaries_hash, (string) $row->boundaries_hash)) {
                throw new RuntimeException('BT-03E effect bin identity did not match its fixed source scope.');
            }
        }
        foreach ($scopes as $scope) {
            if (($rowCounts[$scope->stat_code] ?? 0) !== (int) $scope->effect_count) {
                throw new RuntimeException('BT-03E effect count did not match its fixed source scope.');
            }
            $scopeEffects = array_values(array_filter($rows, fn (object $row): bool => $row->stat_code === $scope->stat_code));
            $actualManifest = $this->manifests->fromPersisted(
                new BacktestBinEffectScope((array) $scope),
                Bt03eContract::SOURCE_FOLD,
                (string) $scope->stat_code,
                $scopeEffects,
            );
            if (! hash_equals((string) $scope->effect_manifest_hash, $actualManifest)) {
                throw new RuntimeException('BT-03E fixed source scope effect manifest mismatched.');
            }
        }

        return $rows;
    }

    /** @param list<object> $rows */
    public function semanticDigest(array $rows): string
    {
        return $this->hasher->hash(array_map(fn (object $row): array => [
            'stat_code' => (string) $row->stat_code,
            'label_code' => (string) $row->label_code,
            'bin_index' => (int) $row->bin_index,
            'bin_origin' => (string) $row->bin_origin,
            'bin_kind' => (string) $row->bin_kind,
            'lower_bound' => $this->nullableFloat($row->lower_bound),
            'upper_bound' => $this->nullableFloat($row->upper_bound),
            'category_value' => $row->category_value === null ? null : (string) $row->category_value,
            'training_sample_count' => (int) $row->training_sample_count,
            'source_backtest_effect_bin_id' => (int) $row->source_backtest_effect_bin_id,
            'boundaries_hash' => (string) $row->boundaries_hash,
            'evaluation_status' => (string) $row->evaluation_status,
            'centered_ci_status' => (string) $row->centered_ci_status,
            'centered_baseline_residual_mean' => $this->nullableFloat($row->centered_baseline_residual_mean),
            'centered_baseline_residual_ci_lower' => $this->nullableFloat($row->centered_baseline_residual_ci_lower),
            'centered_baseline_residual_ci_upper' => $this->nullableFloat($row->centered_baseline_residual_ci_upper),
            'effect_hash' => (string) $row->effect_hash,
        ], $rows));
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new RuntimeException('BT-03E effect semantic digest contained an invalid number.');
        }

        return (float) $value;
    }
}
