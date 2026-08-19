<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

class Bt03ProductionSchemaService
{
    /** @return array<string, bool> */
    public function readiness(): array
    {
        return [
            'backtest_bin_effects' => Schema::hasTable('backtest_bin_effects'),
            'backtest_bin_effect_scopes' => Schema::hasTable('backtest_bin_effect_scopes'),
            'bt03_centered_residual' => Schema::hasTable('backtest_bin_effects')
                && Schema::hasColumns('backtest_bin_effects', [
                    'overall_baseline_residual_mean',
                    'centered_baseline_residual_mean',
                    'centered_baseline_residual_ci_lower',
                    'centered_baseline_residual_ci_upper',
                    'centered_ci_status',
                    'centered_bootstrap_valid_iterations',
                ]),
        ];
    }

    public function assertReady(): void
    {
        $missing = array_keys(array_filter($this->readiness(), fn (bool $ready): bool => ! $ready));
        if ($missing !== []) {
            throw new RuntimeException('BT-03 Production schema was not ready: '.implode(', ', $missing).'.');
        }
    }
}
