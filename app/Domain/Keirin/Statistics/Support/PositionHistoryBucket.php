<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

/** Shared streaming arithmetic; no database or source selection. */
final class PositionHistoryBucket
{
    private array $bucket = ['history_count' => 0, 'history_hash' => null, 'win_count' => 0, 'top2_count' => 0,
        'top3_count' => 0, 'rank_count' => 0, 'rank_sum' => 0.0, 'finish_count' => 0, 'finish_sum' => 0.0,
        'residual_count' => 0, 'residual_sum' => 0.0, 'source_max_fetched_at' => null];

    public function __construct(private readonly Batch04CalculatorSupport $support) {}

    public function add(string $eventHash, ?int $rank, ?float $finishPercentile, ?float $residual, string $fetchedAt): void
    {
        $b = &$this->bucket;
        $b['history_count']++;
        $b['history_hash'] = $this->support->hash(['previous_history_hash' => $b['history_hash'], 'event_hash' => $eventHash]);
        if ($rank !== null) {
            $b['rank_count']++;
            $b['rank_sum'] += $rank;
            $b['win_count'] += $rank === 1 ? 1 : 0;
            $b['top2_count'] += $rank <= 2 ? 1 : 0;
            $b['top3_count'] += $rank <= 3 ? 1 : 0;
        }
        if ($finishPercentile !== null) {
            $b['finish_count']++;
            $b['finish_sum'] += $finishPercentile;
        }
        if ($residual !== null) {
            $b['residual_count']++;
            $b['residual_sum'] += $residual;
        }
        $b['source_max_fetched_at'] = max((string) ($b['source_max_fetched_at'] ?? ''), $fetchedAt);
    }

    public function summary(): array
    {
        $b = $this->bucket;
        $n = $b['history_count'];

        return ['history_count' => $n, 'history_hash' => $b['history_hash'], 'sample_count' => $n,
            'win_count' => $b['win_count'], 'win_rate' => $n > 0 ? $b['win_count'] / $n : null,
            'top2_count' => $b['top2_count'], 'top2_rate' => $n > 0 ? $b['top2_count'] / $n : null,
            'top3_count' => $b['top3_count'], 'top3_rate' => $n > 0 ? $b['top3_count'] / $n : null,
            'mean_rank' => $b['rank_count'] > 0 ? $b['rank_sum'] / $b['rank_count'] : null,
            'mean_finish_strength_percentile' => $b['finish_count'] > 0 ? $b['finish_sum'] / $b['finish_count'] : null,
            'residual_sample_count' => $b['residual_count'],
            'mean_score_expectation_residual' => $b['residual_count'] > 0 ? $b['residual_sum'] / $b['residual_count'] : null,
            'source_max_fetched_at' => $n > 0 ? $b['source_max_fetched_at'] : null];
    }
}
