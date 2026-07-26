<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat01BuildSummaryDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceCalculationDto;
use App\Domain\Keirin\Statistics\Enums\StatDataQualityStatus;
use App\Domain\Keirin\Statistics\Enums\StatFeatureStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticRunStatus;
use App\Models\Race;
use App\Models\StatisticCalculationRun;
use App\Repositories\StatisticFeatureRepository;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class Stat01BuildService
{
    public function __construct(
        private readonly StatisticFeatureRepository $statistics,
        private readonly RaceEntrySnapshotService $entrySnapshots,
        private readonly StatInputAsOfResolver $inputAsOf,
        private readonly Stat01RaceInputFactory $inputs,
        private readonly Stat01Calculator $calculator,
    ) {}

    public function build(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?int $raceId,
        int $chunkSize,
        bool $dryRun,
        bool $recalculate,
    ): Stat01BuildSummaryDto {
        $parameters = [
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
            'race_id' => $raceId,
            'chunk' => $chunkSize,
            'dry_run' => $dryRun,
            'recalculate' => $recalculate,
        ];
        $run = $dryRun ? null : StatisticCalculationRun::query()->create([
            'stat_code' => Stat01Calculator::STAT_CODE,
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'status' => StatisticRunStatus::Running->value,
            'target_from' => $from,
            'target_to' => $to,
            'target_race_id' => $raceId,
            'parameters' => $parameters,
            'started_at' => new DateTimeImmutable('now'),
        ]);
        $counts = [
            'target_races' => 0,
            'processed_races' => 0,
            'targets' => 0,
            'success' => 0,
            'partial' => 0,
            'missing' => 0,
            'invalid' => 0,
            'errors' => 0,
        ];
        $errors = [];

        try {
            $this->statistics->assertStat01Definitions();
            $this->statistics->eachTargetRace(
                $from,
                $to,
                $raceId,
                $chunkSize,
                function (Race $race) use (&$counts, &$errors, $run, $dryRun, $recalculate): void {
                    $counts['target_races']++;
                    $counts['targets'] += $race->entries->count();
                    try {
                        $calculation = $dryRun
                            ? $this->calculateWithoutPersistence($race)
                            : DB::transaction(fn (): Stat01RaceCalculationDto => $this->calculateAndPersist(
                                $run,
                                $race,
                                $recalculate,
                            ));
                        $counts['processed_races']++;
                        foreach ($calculation->results as $result) {
                            if ($result->status === StatFeatureStatus::InvalidInput
                                || $result->status === StatFeatureStatus::ConflictedInput) {
                                $counts['invalid']++;
                            } elseif ($result->status === StatFeatureStatus::MissingInput
                                || $result->status === StatFeatureStatus::Blocked) {
                                $counts['missing']++;
                            } elseif ($result->dataQualityStatus === StatDataQualityStatus::Partial) {
                                $counts['partial']++;
                            } elseif ($result->status === StatFeatureStatus::LeakageRisk
                                || $result->status === StatFeatureStatus::Error) {
                                $counts['errors']++;
                            } else {
                                $counts['success']++;
                            }
                        }
                    } catch (QueryException $exception) {
                        throw $exception;
                    } catch (Throwable $throwable) {
                        $counts['errors']++;
                        if (count($errors) < 20) {
                            $errors[] = "race:{$race->id} {$throwable->getMessage()}";
                        }
                    }
                },
            );
        } catch (Throwable $throwable) {
            $counts['errors']++;
            if (count($errors) < 20) {
                $errors[] = $throwable->getMessage();
            }
            if ($run instanceof StatisticCalculationRun) {
                $this->finishRun($run, $counts, $errors, StatisticRunStatus::Failed);
            }

            throw $throwable;
        }

        $status = match (true) {
            $counts['target_races'] === 0 => StatisticRunStatus::NoTargets,
            $counts['errors'] > 0 && $counts['processed_races'] === 0 => StatisticRunStatus::Failed,
            $counts['errors'] > 0 => StatisticRunStatus::PartiallyFailed,
            default => StatisticRunStatus::Succeeded,
        };
        if ($run instanceof StatisticCalculationRun) {
            $run = $this->finishRun($run, $counts, $errors, $status);
        }

        return new Stat01BuildSummaryDto(
            run: $run,
            targetRaceCount: $counts['target_races'],
            processedRaceCount: $counts['processed_races'],
            targetCount: $counts['targets'],
            successCount: $counts['success'],
            partialCount: $counts['partial'],
            missingCount: $counts['missing'],
            invalidCount: $counts['invalid'],
            errorCount: $counts['errors'],
            errors: $errors,
            dryRun: $dryRun,
        );
    }

    private function calculateWithoutPersistence(Race $race): Stat01RaceCalculationDto
    {
        $asOf = $this->inputAsOf->resolve($race);
        $snapshots = $this->entrySnapshots->snapshotsForRace($race, $asOf, false);

        return $this->calculator->calculate($this->inputs->make($race, $snapshots, $asOf));
    }

    private function calculateAndPersist(
        ?StatisticCalculationRun $run,
        Race $race,
        bool $recalculate,
    ): Stat01RaceCalculationDto {
        if (! $run instanceof StatisticCalculationRun) {
            throw new \LogicException('A persisted STAT-01 build requires a calculation run.');
        }
        $asOf = $this->inputAsOf->resolve($race);
        $snapshots = $this->entrySnapshots->snapshotsForRace($race, $asOf, true);
        $input = $this->inputs->make($race, $snapshots, $asOf);
        $calculatedAt = new DateTimeImmutable('now');
        $calculation = $this->calculator->calculate($input);
        $this->statistics->persistStat01(
            $run,
            $race,
            $input,
            $calculation,
            $snapshots,
            $calculatedAt,
            $recalculate,
        );

        return $calculation;
    }

    /**
     * @param  array<string,int>  $counts
     * @param  list<string>  $errors
     */
    private function finishRun(
        StatisticCalculationRun $run,
        array $counts,
        array $errors,
        StatisticRunStatus $status,
    ): StatisticCalculationRun {
        $run->forceFill([
            'status' => $status->value,
            'finished_at' => new DateTimeImmutable('now'),
            'target_race_count' => $counts['target_races'],
            'processed_race_count' => $counts['processed_races'],
            'target_count' => $counts['targets'],
            'success_count' => $counts['success'],
            'partial_count' => $counts['partial'],
            'missing_count' => $counts['missing'],
            'invalid_count' => $counts['invalid'],
            'error_count' => $counts['errors'],
            'error_summary' => $errors === [] ? null : implode("\n", $errors),
        ])->save();

        return $run;
    }
}
