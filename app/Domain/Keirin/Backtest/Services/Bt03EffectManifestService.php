<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\DTO\Bt03ComputedBinEffectDto;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Models\BacktestBinEffectScope;
use RuntimeException;

class Bt03EffectManifestService
{
    public const SCOPE_VERSION = 'BT03-SCOPE-EFFECT-MANIFEST-v1';

    public const RUN_VERSION = 'BT03-RUN-EFFECT-MANIFEST-v1';

    public function __construct(private readonly Bt02ModelArtifactHasher $hasher) {}

    /** @param list<Bt03ComputedBinEffectDto> $effects */
    public function fromComputed(BacktestBinEffectScope $scope, string $foldCode, string $statCode, array $effects): string
    {
        return $this->scope($scope, $foldCode, $statCode, array_map(
            fn (Bt03ComputedBinEffectDto $effect): array => [
                'label_code' => $effect->labelCode,
                'bin_index' => $effect->bin->binIndex,
                'effect_hash' => $effect->effectHash,
            ],
            $effects,
        ));
    }

    /** @param list<object> $effects */
    public function fromPersisted(BacktestBinEffectScope $scope, string $foldCode, string $statCode, array $effects): string
    {
        return $this->scope($scope, $foldCode, $statCode, array_map(
            fn (object $effect): array => [
                'label_code' => (string) $effect->label_code,
                'bin_index' => (int) $effect->bin_index,
                'effect_hash' => (string) $effect->effect_hash,
            ],
            $effects,
        ));
    }

    /** @param list<object> $scopes */
    public function run(array $scopes): string
    {
        $rows = [];
        foreach ($scopes as $scope) {
            if (! is_string($scope->effect_manifest_hash)
                || preg_match('/\A[0-9a-f]{64}\z/', $scope->effect_manifest_hash) !== 1) {
                throw new RuntimeException('BT-03 run effect manifest received an invalid scope hash.');
            }
            $rows[] = [
                'fold_code' => (string) $scope->fold_code,
                'stat_code' => (string) $scope->stat_code,
                'cohort_code' => (string) $scope->cohort_code,
                'effect_manifest_hash' => (string) $scope->effect_manifest_hash,
            ];
        }

        return $this->hasher->hash([
            'version' => self::RUN_VERSION,
            'source_manifest_hash' => Bt03SourceManifest::HASH,
            'calculation_version' => Bt03BinEffectCalculator::CALCULATION_VERSION,
            'scopes' => $rows,
        ]);
    }

    /** @param list<array{label_code: string, bin_index: int, effect_hash: string}> $effects */
    private function scope(BacktestBinEffectScope $scope, string $foldCode, string $statCode, array $effects): string
    {
        $labelOrder = array_flip(Bt03ProductionContract::LABELS);
        usort($effects, fn (array $left, array $right): int => [
            $labelOrder[$left['label_code']] ?? PHP_INT_MAX,
            $left['bin_index'],
        ] <=> [
            $labelOrder[$right['label_code']] ?? PHP_INT_MAX,
            $right['bin_index'],
        ]);
        foreach ($effects as $effect) {
            if (! isset($labelOrder[$effect['label_code']])
                || $effect['bin_index'] < 0
                || preg_match('/\A[0-9a-f]{64}\z/', $effect['effect_hash']) !== 1) {
                throw new RuntimeException('BT-03 scope effect manifest row was invalid.');
            }
        }

        return $this->hasher->hash([
            'version' => self::SCOPE_VERSION,
            'source_run_id' => (int) $scope->source_backtest_run_id,
            'source_fold_id' => (int) $scope->source_backtest_fold_id,
            'source_signal_spec_id' => (int) $scope->source_backtest_signal_spec_id,
            'fold_code' => $foldCode,
            'stat_code' => $statCode,
            'cohort_code' => (string) $scope->cohort_code,
            'bootstrap_iterations' => (int) $scope->bootstrap_iterations,
            'bootstrap_seed' => (int) $scope->bootstrap_seed,
            'effects' => $effects,
        ]);
    }
}
