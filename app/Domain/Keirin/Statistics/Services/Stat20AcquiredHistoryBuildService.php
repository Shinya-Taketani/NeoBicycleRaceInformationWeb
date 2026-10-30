<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat20AcquiredHistoryCalculator;
use App\Domain\Keirin\Statistics\DTO\Stat20AcquiredHistoryOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat20BuildSummaryDto;
use App\Domain\Keirin\Statistics\Repositories\Stat20AcquiredHistoryInputRepository;
use App\Domain\Keirin\Statistics\Support\Stat20AcquiredHistoryContract as Contract;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Stat20AcquiredHistoryBuildService
{
    public function __construct(private readonly Stat20AcquiredHistoryInputRepository $inputs, private readonly Stat20AcquiredHistoryCalculator $calculator) {}

    public function build(Stat20AcquiredHistoryOptionsDto $options): Stat20BuildSummaryDto
    {
        $runs = $this->inputs->selectedRuns($options);
        $targets = $this->inputs->counts($options);
        if ($targets->races === 0 || $targets->entries === 0) {
            throw new RuntimeException('No fixed STAT01 targets in the permitted STAT-20 v2 scope.');
        }
        $run = $options->dryRun ? null : StatisticFeatureRun::query()->create([
            'run_uuid' => (string) Str::uuid(), 'stat_code' => 'STAT-20', 'calculation_version' => Contract::VERSION,
            'mode' => 'BACKFILL', 'status' => 'RUNNING', 'target_from' => $options->from?->format('Y-m-d'),
            'target_to' => $options->to?->format('Y-m-d'), 'target_race_id' => $options->raceId,
            'input_as_of_policy' => 'STAT01_RUN_RESULT_INPUT_AS_OF', 'target_race_count' => $targets->races, 'target_entry_count' => $targets->entries,
            'parameters' => ['stat01_run_id' => $options->stat01RunId, 'source_run_ids' => array_map(fn ($r) => (int) $r->id, $runs),
                'source_contract' => Contract::VERSION, 'chunk' => $options->chunkSize, 'confidence_policy' => 'NOT_DEFINED'], 'started_at' => now(),
        ]);
        $counts = ['processed_races' => 0, 'results' => 0, 'valid' => 0, 'partial' => 0, 'missing' => 0, 'invalid' => 0, 'errors' => 0];
        $errors = [];
        $record = static function (Throwable $e, ?int $raceId) use (&$counts, &$errors): void {
            $counts['errors']++;
            if (count($errors) < 100) {
                $errors[] = ['race_id' => $raceId, 'error_type' => $e::class, 'error_message' => $e->getMessage()];
            }
        };
        $activeRace = null;
        $recorded = false;
        try {
            foreach ($this->inputs->raceIds($options) as $id) {
                $activeRace = $id;
                $recorded = false;
                $counts['processed_races']++;
                $item = null;
                try {
                    $item = $run === null ? null : StatisticFeatureRunItem::query()->create(['feature_run_id' => $run->id, 'race_id' => $id,
                        'status' => 'RUNNING', 'attempt_count' => 1, 'started_at' => now()]);
                    $results = $this->calculator->calculate($this->inputs->raceInput($options, $runs, $id));
                    if ($run !== null) {
                        DB::transaction(function () use ($run, $item, $results): void {
                            foreach ($results as $r) {
                                $t = $r->target;
                                StatisticFeatureResult::query()->create(['feature_run_id' => $run->id, 'stat_code' => 'STAT-20',
                                    'calculation_version' => Contract::VERSION, 'subject_type' => 'RACE_ENTRY', 'subject_key' => 'race_entry:'.$t->raceEntryId,
                                    'race_id' => $t->raceId, 'race_entry_id' => $t->raceEntryId, 'player_id' => $t->playerId, 'bike_number' => $t->bikeNumber,
                                    'status' => $r->status->value, 'quality_status' => $r->qualityStatus->value, 'acquisition_mode' => 'BACKFILL',
                                    'input_as_of' => $t->inputAsOf, 'source_fetched_at' => $r->sourceFetchedAt, 'features' => $r->features,
                                    'evidence' => $r->evidence, 'input_hash' => $r->inputHash, 'raw_points' => null, 'confidence' => null,
                                    'effective_points' => null, 'calculated_at' => now()]);
                            }
                            $item->forceFill(['status' => 'PARTIAL', 'feature_result_count' => count($results), 'finished_at' => now()])->save();
                        });
                    }
                    foreach ($results as $r) {
                        $counts['results']++;
                        $counts[match ($r->status->value) {
                            'VALID' => 'valid', 'INVALID_INPUT' => 'invalid', 'MISSING_INPUT' => 'missing', default => 'partial'
                        }]++;
                    }
                } catch (Throwable $e) {
                    $record($e, $id);
                    $recorded = true;
                    $item?->forceFill(['status' => 'FAILED', 'feature_result_count' => 0, 'error_type' => $e::class,
                        'error_message' => $e->getMessage(), 'finished_at' => now()])->save();
                }
                $activeRace = null;
                $recorded = false;
            }
        } catch (Throwable $e) {
            if (! $recorded) {
                $record($e, $activeRace);
            }
        } finally {
            $run?->forceFill(['status' => $counts['errors'] > 0 && $counts['results'] === 0 ? 'FAILED' : 'PARTIALLY_SUCCEEDED',
                'processed_race_count' => $counts['processed_races'], 'success_count' => $counts['valid'], 'partial_count' => $counts['partial'],
                'missing_count' => $counts['missing'], 'invalid_count' => $counts['invalid'], 'error_count' => $counts['errors'],
                'error_summary' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR), 'finished_at' => now()])->save();
        }

        return new Stat20BuildSummaryDto($run?->id, $options->dryRun, $targets->races, $targets->entries, $counts);
    }
}
