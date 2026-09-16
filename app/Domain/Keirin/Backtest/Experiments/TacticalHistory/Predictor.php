<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use RuntimeException;

final class Predictor
{
    public function __construct(private readonly Bt03e03ProbabilityScorer $scorer, private readonly Bt03e06WinnerConditionedDecoder $decoder) {}

    public function predict(array $race, Bt03e03FitResultDto $fit, bool $controlReconstruction = false): array
    {
        foreach ($race['entries'] as &$entry) {
            if (array_diff(array_keys($entry), ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'bins']) !== []) {
                throw new RuntimeException('Prediction input contained unapproved fields.');
            }
            // The frozen scorer copies these legacy fields but never consumes them mathematically.
            $entry['rank'] = $entry['status'] = null;
        }
        unset($entry);
        $probabilities = $this->scorer->predict($race, $fit);
        foreach ($probabilities['entries'] as &$entry) {
            unset($entry['rank'], $entry['status']);
        }
        unset($entry);

        $decision = $this->decoder->decode($probabilities);
        $decision['reconstruction_verified'] = $controlReconstruction;
        $decision['prediction_origin'] = $controlReconstruction ? 'RECONSTRUCTED_CONTROL' : 'EXPERIMENTAL_REFIT';

        return ['probabilities' => $probabilities, 'decision' => $decision];
    }
}
