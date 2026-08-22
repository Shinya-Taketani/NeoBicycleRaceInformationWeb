<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use App\Domain\Keirin\Backtest\Services\Bt03EffectManifestService;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Models\BacktestBinEffectScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Bt03eRuleSourceRepository
{
    public function __construct(private readonly Bt03EffectManifestService $manifests) {}

    /** @return array<string, int|string> */
    public function auditState(): array
    {
        $run = DB::table('backtest_runs')->where('id', Bt03eContract::SOURCE_RUN_ID)->first()
            ?? throw new RuntimeException('BT-03E fixed source run 6 was unavailable.');

        return [
            'status' => (string) $run->status,
            'parameters_sha256' => hash('sha256', (string) $run->parameters),
            'updated_at' => (string) $run->updated_at,
            'scope_count' => DB::table('backtest_bin_effect_scopes')->where('backtest_run_id', Bt03eContract::SOURCE_RUN_ID)->count(),
            'effect_count' => DB::table('backtest_bin_effects')->where('backtest_run_id', Bt03eContract::SOURCE_RUN_ID)->count(),
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
}
