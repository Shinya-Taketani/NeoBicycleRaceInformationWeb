<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Services\Bt03EffectManifestService;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use Mockery;
use Tests\TestCase;

class Bt03eRuleSourceSemanticDigestTest extends TestCase
{
    public function test_digest_changes_when_a_rule_used_effect_field_drifts(): void
    {
        $repository = new Bt03eRuleSourceRepository(
            Mockery::mock(Bt03EffectManifestService::class),
            new Bt02ModelArtifactHasher,
        );
        $before = $this->row();
        $after = clone $before;
        $after->centered_baseline_residual_ci_upper = '0.12500000000000001';

        $this->assertNotSame(
            $repository->semanticDigest([$before]),
            $repository->semanticDigest([$after]),
        );
    }

    private function row(): object
    {
        return (object) [
            'stat_code' => 'STAT-07',
            'label_code' => 'IS_WIN',
            'bin_index' => 1,
            'bin_origin' => 'TRAINING_BIN',
            'bin_kind' => 'NUMERIC_RANGE',
            'lower_bound' => '1.25',
            'upper_bound' => '2.5',
            'category_value' => null,
            'training_sample_count' => 123,
            'source_backtest_effect_bin_id' => 456,
            'boundaries_hash' => str_repeat('a', 64),
            'evaluation_status' => 'AVAILABLE',
            'centered_ci_status' => 'AVAILABLE',
            'centered_baseline_residual_mean' => '0.1',
            'centered_baseline_residual_ci_lower' => '0.05',
            'centered_baseline_residual_ci_upper' => '0.12',
            'effect_hash' => str_repeat('b', 64),
        ];
    }
}
