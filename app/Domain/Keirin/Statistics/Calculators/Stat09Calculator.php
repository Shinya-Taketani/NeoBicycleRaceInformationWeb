<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;

final class Stat09Calculator
{
    public const VERSION = 'STAT-09-acquired-career-v2';

    public function __construct(private readonly AcquiredHistorySupport $history) {}

    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, array $stages = []): Batch02FeatureResultDto
    {
        $validated = $this->history->prepare($target, $histories, $options);
        $rows = $validated['events'];
        $years = [];
        foreach ($rows as $row) {
            $years[AcquiredHistorySupport::jst($row->scheduledStartAt)->format('Y')][] = $row;
        }
        ksort($years);
        $features = ['career_completeness' => 'PARTIAL_CAREER', 'ACQUIRED_PERIOD' => $this->counts($rows), 'YEARLY' => [],
            'lifetime_total' => null, 'lifetime_championships' => null, 'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false];
        foreach ($years as $year => $events) {
            $features['YEARLY'][$year] = $this->counts($events);
        }
        $groups = $usedStages = [];
        foreach ($rows as $row) {
            $usedStages[$row->raceId] = $stages[$row->raceId] ?? null;
            $groups[$usedStages[$row->raceId]['stage'] ?? 'UNKNOWN'][] = $row;
        }
        ksort($groups);
        $features['STAGE_BREAKDOWN'] = array_map($this->counts(...), $groups);

        return new Batch02FeatureResultDto($target, $target->playerId === null ? StatisticFeatureResultStatus::MissingInput
            : ($rows === [] ? StatisticFeatureResultStatus::NoHistory : StatisticFeatureResultStatus::Partial), StatisticQualityStatus::Degraded, $features,
            $validated['evidence'] + ['scope' => 'ACQUIRED_PERIOD_NOT_LIFETIME', 'quality_reasons' => ['PARTIAL_CAREER'],
                'unavailable' => ['complete_career', 'official_championship_history']], hash('sha256', self::VERSION.':'.$validated['hash'].':'.json_encode($usedStages, JSON_THROW_ON_ERROR)));
    }

    private function counts(array $rows): array
    {
        $started = array_filter($rows, fn ($e) => $e->started());
        $normal = array_filter($started, fn ($e) => $e->normalFinish() && $e->rank !== null);
        $counts = [];
        for ($rank = 1; $rank <= 3; $rank++) {
            $counts[$rank] = count(array_filter($normal, fn ($e) => $e->rank === $rank));
        }

        return ['observed_rows' => count($rows), 'started_count' => count($started), 'normal_rank_count' => count($normal),
            'rank_1_count' => $rows === [] ? null : $counts[1], 'rank_2_count' => $rows === [] ? null : $counts[2],
            'rank_3_count' => $rows === [] ? null : $counts[3],
            'win_rate_among_normal_finishes' => $normal === [] ? null : $counts[1] / count($normal),
            'top3_rate_among_normal_finishes' => $normal === [] ? null : array_sum($counts) / count($normal),
            'excluded_abnormal_or_missing_rank' => count($started) - count($normal)];
    }
}
