<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\Contracts\Batch03Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch03Stat;
use App\Domain\Keirin\Statistics\Enums\RaceStage;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch03CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;

class Stat31Calculator implements Batch03Calculator
{
    public function __construct(
        private readonly Batch03CalculatorSupport $support,
        private readonly StatisticalMath $math,
    ) {}

    public function stat(): Batch03Stat
    {
        return Batch03Stat::Stat31;
    }

    public function calculate(Batch03TargetEntryDto $target, array $histories, Batch03BuildOptionsDto $options, string $batchExecutionUuid): Batch03FeatureResultDto
    {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $semifinals = $this->stage($context->histories, RaceStage::Semifinal);
        $finals = $this->stage($context->histories, RaceStage::Final);
        $high = [...$semifinals, ...$finals];
        usort($high, fn (Batch03HistoricalRaceDto $left, Batch03HistoricalRaceDto $right): int => $left->scheduledStartAt <=> $right->scheduledStartAt);
        $sameGrade = $target->meetingGrade !== null ? array_values(array_filter(
            $context->histories,
            fn (Batch03HistoricalRaceDto $event): bool => $event->meetingGrade === $target->meetingGrade,
        )) : [];
        $gradeDistribution = [];
        $classDistribution = ['A' => 0, 'S' => 0, 'UNKNOWN' => 0];
        foreach ($context->histories as $event) {
            $grade = $event->meetingGrade ?? 'UNKNOWN';
            $gradeDistribution[$grade] = ($gradeDistribution[$grade] ?? 0) + 1;
            $class = match (true) {
                str_starts_with((string) $event->rawRaceType, 'Ａ級') => 'A',
                str_starts_with((string) $event->rawRaceType, 'Ｓ級') => 'S',
                default => 'UNKNOWN',
            };
            $classDistribution[$class]++;
        }
        ksort($gradeDistribution);
        $normal = array_values(array_filter($context->histories, fn (Batch03HistoricalRaceDto $event): bool => $event->normalFinish()));
        $contextMeans = array_values(array_filter(array_map(fn (Batch03HistoricalRaceDto $event): ?float => $event->raceScoreMean, $normal), fn (?float $value): bool => $value !== null));
        $contextMaxima = array_values(array_filter(array_map(fn (Batch03HistoricalRaceDto $event): ?float => $event->raceScoreMax, $normal), fn (?float $value): bool => $value !== null));
        $contextSpreads = array_values(array_filter(array_map(fn (Batch03HistoricalRaceDto $event): ?float => $event->raceScoreStddevPop, $normal), fn (?float $value): bool => $value !== null));
        $subjectPercentiles = array_values(array_filter(array_map(fn (Batch03HistoricalRaceDto $event): ?float => $event->subjectScorePercentile, $normal), fn (?float $value): bool => $value !== null));
        $status = match (true) {
            $target->playerId === null => StatisticFeatureResultStatus::MissingInput,
            $context->histories === [] => StatisticFeatureResultStatus::NoHistory,
            default => StatisticFeatureResultStatus::Valid,
        };

        return $this->support->result(
            $target,
            $options,
            $context,
            $this->stat(),
            [
                'OBSERVED_HISTORY' => ['sample_count' => count($context->histories)],
                'STAGE_EXPERIENCE' => [
                    'semifinal_count' => count($semifinals),
                    'final_count' => count($finals),
                    'semifinal_or_final_count' => count($high),
                    'SEMIFINAL' => $this->support->performance($semifinals),
                    'FINAL' => $this->support->performance($finals),
                    'SEMIFINAL_OR_FINAL' => $this->support->performance($high),
                ],
                'MEETING_GRADE' => [
                    'target_raw_grade' => $target->meetingGrade,
                    'same_grade_history' => $this->support->performance($sameGrade),
                    'observed_grade_distribution' => $gradeDistribution,
                ],
                'RACE_CLASS_DISTRIBUTION' => $classDistribution,
                'COMPETITION_CONTEXT' => [
                    'sample_count' => count($subjectPercentiles),
                    'mean_race_score_mean' => $this->math->mean($contextMeans),
                    'mean_race_score_max' => $this->math->mean($contextMaxima),
                    'mean_race_score_stddev_pop' => $this->math->mean($contextSpreads),
                    'mean_subject_score_percentile' => $this->math->mean($subjectPercentiles),
                ],
            ],
            $status,
            $target->playerId === null ? ['PLAYER_ID_UNRESOLVED'] : ($context->histories === [] ? ['NO_ACQUIRED_HISTORY'] : []),
            ['FULL_CAREER_HISTORY', 'COMPETITION_SYSTEM_MASTER', 'FINAL_QUALIFICATION_OPPORTUNITY', 'GRADE_WEIGHT'],
        );
    }

    /** @param list<Batch03HistoricalRaceDto> $events @return list<Batch03HistoricalRaceDto> */
    private function stage(array $events, RaceStage $stage): array
    {
        return array_values(array_filter($events, fn (Batch03HistoricalRaceDto $event): bool => $event->normalizedStage === $stage));
    }
}
