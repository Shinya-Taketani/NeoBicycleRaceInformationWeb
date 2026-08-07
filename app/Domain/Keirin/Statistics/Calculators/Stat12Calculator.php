<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\Contracts\Batch02Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\Batch02Stat;
use App\Domain\Keirin\Statistics\Support\Batch02CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use DateTimeImmutable;

class Stat12Calculator implements Batch02Calculator
{
    public function __construct(
        private readonly Batch02CalculatorSupport $support,
        private readonly StatisticalMath $math,
    ) {}

    public function stat(): Batch02Stat
    {
        return Batch02Stat::Stat12;
    }

    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, string $batchExecutionUuid): Batch02FeatureResultDto
    {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $started = $this->started($context->histories);
        $pre = $this->started($context->preMeeting);
        $inMeeting = $this->started($context->inMeeting);
        $normal = array_values(array_filter($context->histories, fn (HistoricalRaceDto $event): bool => $event->normalFinish()));
        $abnormal = array_values(array_filter($context->histories, fn (HistoricalRaceDto $event): bool => $event->abnormal()));
        $previous = $this->last($started);
        $previousNormal = $this->last($normal);
        $previousAbnormal = $this->last($abnormal);
        $previousPre = $this->last($pre);
        $previousIn = $this->last($inMeeting);
        $gaps = [];
        for ($index = 1; $index < count($pre); $index++) {
            $gaps[] = $this->gapDays($pre[$index]->scheduledStartAt, $pre[$index - 1]->scheduledStartAt);
        }
        $currentGap = $previousPre !== null ? $this->gapDays($target->scheduledStartAt, $previousPre->scheduledStartAt) : null;
        $median = $this->math->median($gaps);

        $features = [
            'SUMMARY' => [
                'previous_started_at' => $this->timestamp($previous),
                'previous_started_result_state' => $previous?->resultState->value,
                'gap_from_previous_started_hours' => $previous !== null ? $this->gapHours($target->scheduledStartAt, $previous->scheduledStartAt) : null,
                'gap_from_previous_started_days' => $previous !== null ? $this->gapDays($target->scheduledStartAt, $previous->scheduledStartAt) : null,
                'previous_normal_finish_at' => $this->timestamp($previousNormal),
                'gap_from_previous_normal_finish_days' => $previousNormal !== null ? $this->gapDays($target->scheduledStartAt, $previousNormal->scheduledStartAt) : null,
                'previous_abnormal_at' => $this->timestamp($previousAbnormal),
                'gap_from_previous_abnormal_days' => $previousAbnormal !== null ? $this->gapDays($target->scheduledStartAt, $previousAbnormal->scheduledStartAt) : null,
                'pre_meeting_previous_started_at' => $this->timestamp($previousPre),
                'pre_meeting_gap_days' => $currentGap,
                'in_meeting_previous_started_at' => $this->timestamp($previousIn),
                'in_meeting_gap_days' => $previousIn !== null ? $this->gapDays($target->scheduledStartAt, $previousIn->scheduledStartAt) : null,
            ],
            'HISTORICAL_PRE_MEETING_GAPS' => [
                'historical_gap_sample_count' => count($gaps),
                'mean_gap_days' => $this->math->mean($gaps),
                'median_gap_days' => $median,
                'q25_gap_days' => $this->math->quantile($gaps, 0.25),
                'q75_gap_days' => $this->math->quantile($gaps, 0.75),
                'current_gap_minus_median_days' => $currentGap !== null && $median !== null ? $currentGap - $median : null,
                'current_gap_empirical_percentile' => $currentGap !== null && $gaps !== []
                    ? count(array_filter($gaps, fn (float $gap): bool => $gap <= $currentGap)) / count($gaps)
                    : null,
            ],
        ];

        return $this->support->result($target, $options, $context, $this->stat(), $features, $previousPre !== null && $gaps !== []);
    }

    /** @param list<HistoricalRaceDto> $events @return list<HistoricalRaceDto> */
    private function started(array $events): array
    {
        return array_values(array_filter($events, fn (HistoricalRaceDto $event): bool => $event->started()));
    }

    /** @param list<HistoricalRaceDto> $events */
    private function last(array $events): ?HistoricalRaceDto
    {
        return $events !== [] ? $events[array_key_last($events)] : null;
    }

    private function timestamp(?HistoricalRaceDto $event): ?string
    {
        return $event?->scheduledStartAt->format('Y-m-d\TH:i:s.uP');
    }

    private function gapHours(DateTimeImmutable $later, DateTimeImmutable $earlier): float
    {
        return ($later->getTimestamp() - $earlier->getTimestamp()) / 3600;
    }

    private function gapDays(DateTimeImmutable $later, DateTimeImmutable $earlier): float
    {
        return $this->gapHours($later, $earlier) / 24;
    }
}
