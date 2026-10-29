<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\SimilarConditionsCalculator;
use App\Domain\Keirin\Statistics\Calculators\Stat09Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat25Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat44Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\Repositories\AcquiredHistoryContextRepository;
use App\Domain\Keirin\Statistics\Repositories\HistoricalRaceRepository;
use App\Domain\Keirin\Statistics\Repositories\SimilarityConditionsRepository;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class AcquiredHistoryFeatureBuildService
{
    public function __construct(private readonly HistoricalRaceRepository $inputs, private readonly Stat25Calculator $calculator,
        private readonly Stat09Calculator $career, private readonly Stat44Calculator $longitudinal,
        private readonly AcquiredHistoryContextRepository $context,
        private readonly SimilarityConditionsRepository $conditions, private readonly SimilarConditionsCalculator $similar) {}

    public function build(Batch02BuildOptionsDto $options, string $stat = 'STAT-25', ?array $schoolProfile = null): array
    {
        $version = match ($stat) {
            'STAT-25' => Stat25Calculator::VERSION,
            'STAT-09' => Stat09Calculator::VERSION,
            'STAT-44' => Stat44Calculator::VERSION,
            'STAT-16', 'STAT-27' => SimilarConditionsCalculator::VERSIONS[$stat],
            default => throw new InvalidArgumentException('Unsupported acquired-history statistic.'),
        };
        if ($options->historyFrom->format('Y') < '2022' || $options->chunkSize < 1 || $options->chunkSize > 1000
            || ($options->raceId === null && ($options->from === null || $options->to === null || $options->from > $options->to))) {
            throw new InvalidArgumentException('Invalid STAT-25 development scope.');
        }
        // Fence the selected targets before invoking the shared, older history repository.
        $targets = DB::table('races')->when($options->raceId !== null,
            fn ($q) => $q->where('id', $options->raceId),
            fn ($q) => $q->whereBetween('race_date', [$options->from->format('Y-m-d'), $options->to->format('Y-m-d')]));
        if ((clone $targets)->where(fn ($q) => $q->where('race_date', '<', '2022-01-01')->orWhere('race_date', '>', '2025-12-31'))->exists()) {
            throw new InvalidArgumentException('STAT-25 target outside 2022-2025.');
        }
        $this->inputs->validatedStat01Run($options->stat01RunId);
        $counts = $this->inputs->counts($options);
        if ($counts->races === 0 || $counts->entries === 0) {
            throw new InvalidArgumentException('No selected STAT-01 targets.');
        }
        $run = null;
        $summary = ['run_id' => null, 'dry_run' => $options->dryRun, 'target_races' => $counts->races,
            'target_entries' => $counts->entries, 'processed_races' => 0, 'results' => 0, 'statuses' => [], 'errors' => 0];
        if (! $options->dryRun) {
            $run = StatisticFeatureRun::query()->create([
                'run_uuid' => (string) Str::uuid(), 'stat_code' => $stat, 'calculation_version' => $version,
                'mode' => 'BACKFILL', 'status' => 'RUNNING', 'history_from' => $options->historyFrom->format('Y-m-d'),
                'target_from' => $options->from?->format('Y-m-d'), 'target_to' => $options->to?->format('Y-m-d'), 'target_race_id' => $options->raceId,
                'input_as_of_policy' => 'STAT01_RUN_RESULT_INPUT_AS_OF', 'parameters' => $options->parameters((string) Str::uuid()),
                'target_race_count' => $counts->races, 'target_entry_count' => $counts->entries, 'started_at' => new DateTimeImmutable,
            ]);
            $summary['run_id'] = $run->id;
        }
        try {
            foreach ($this->inputs->raceInputs($options) as $race) {
                $results = [];
                foreach ($race->entries as $target) {
                    $history = $race->historiesByPlayer[$target->playerId] ?? [];
                    $profile = $stat === 'STAT-44' ? $this->context->matchedSchoolProfile($target->raceEntryId, $schoolProfile) : null;
                    $results[] = match ($stat) {
                        'STAT-25' => $this->calculator->calculate($target, $history, $options, $this->context->stages($history)),
                        'STAT-09' => $this->career->calculate($target, $history, $options, $this->context->stages($history)),
                        'STAT-44' => $this->longitudinal->calculate($target, $history, $options,
                            $this->context->birthDate($target->playerId),
                            ($profile['debut_date'] ?? null) === null ? null : new DateTimeImmutable($profile['debut_date']), $profile['source'] ?? null),
                        'STAT-16', 'STAT-27' => $this->similar->calculate($target, $history, $options, $this->conditions->forTarget($target, $history))[$stat],
                    };
                }
                if ($run !== null) {
                    DB::transaction(function () use ($run, $race, $results): void {
                        foreach ($results as $out) {
                            StatisticFeatureResult::query()->create([
                                'feature_run_id' => $run->id, 'stat_code' => $run->stat_code, 'calculation_version' => $run->calculation_version,
                                'subject_type' => 'RACE_ENTRY', 'subject_key' => 'race_entry:'.$out->target->raceEntryId,
                                'race_id' => $race->raceId, 'race_entry_id' => $out->target->raceEntryId, 'player_id' => $out->target->playerId,
                                'bike_number' => $out->target->bikeNumber, 'status' => $out->status->value, 'quality_status' => $out->qualityStatus->value,
                                'acquisition_mode' => 'BACKFILL', 'input_as_of' => $out->target->inputAsOf, 'features' => $out->features,
                                'source_fetched_at' => $out->evidence['source_max_fetched_at'] ?? null,
                                'evidence' => $out->evidence, 'input_hash' => $out->inputHash, 'raw_points' => null, 'confidence' => null,
                                'effective_points' => null, 'calculated_at' => new DateTimeImmutable,
                            ]);
                        }
                        StatisticFeatureRunItem::query()->create(['feature_run_id' => $run->id, 'race_id' => $race->raceId,
                            'status' => count(array_filter($results, fn ($r) => $r->status->value !== 'VALID')) > 0 ? 'PARTIAL' : 'SUCCEEDED',
                            'attempt_count' => 1, 'feature_result_count' => count($results),
                            'started_at' => new DateTimeImmutable, 'finished_at' => new DateTimeImmutable]);
                    });
                }
                $summary['processed_races']++;
                foreach ($results as $out) {
                    $summary['results']++;
                    $summary['statuses'][$out->status->value] = ($summary['statuses'][$out->status->value] ?? 0) + 1;
                }
            }
        } catch (Throwable $error) {
            $run?->forceFill(['status' => 'FAILED', 'error_count' => 1, 'finished_at' => new DateTimeImmutable])->save();
            throw $error;
        }
        $run?->forceFill(['status' => isset($summary['statuses']['VALID']) && count($summary['statuses']) === 1 ? 'SUCCEEDED' : 'PARTIALLY_SUCCEEDED',
            'processed_race_count' => $summary['processed_races'], 'success_count' => $summary['statuses']['VALID'] ?? 0,
            'partial_count' => ($summary['statuses']['NO_HISTORY'] ?? 0) + ($summary['statuses']['PARTIAL_HISTORY'] ?? 0) + ($summary['statuses']['PARTIAL'] ?? 0),
            'missing_count' => $summary['statuses']['MISSING_INPUT'] ?? 0, 'finished_at' => new DateTimeImmutable])->save();

        return $summary;
    }
}
