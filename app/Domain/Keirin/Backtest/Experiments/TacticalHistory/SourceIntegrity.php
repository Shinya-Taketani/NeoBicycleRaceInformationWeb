<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use RuntimeException;

final class SourceIntegrity
{
    /** The external read-only snapshot runner supplies both existing checks. */
    public function verify(array $expectedFeatures, callable $features, callable $history): array
    {
        $actualFeatures = $features();
        if ($actualFeatures !== $expectedFeatures) {
            throw new RuntimeException('Fixed feature sources drifted after input generation.');
        }
        $actualHistory = $history();
        if (($actualHistory['status'] ?? null) !== 'VERIFIED_CURRENT_STATE_AGAINST_INPUT_SNAPSHOT') {
            throw new RuntimeException('History end verification was not successful.');
        }

        return ['features' => $actualFeatures, 'history' => $actualHistory, 'status' => 'VERIFIED_CURRENT_STATE_AGAINST_INPUT_SNAPSHOT'];
    }
}
