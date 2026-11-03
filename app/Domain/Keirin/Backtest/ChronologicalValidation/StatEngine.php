<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\DTO\Bt02SignalFeatureDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\ArtifactCalculationSource;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\Support\RaceStageNormalizer;
use Illuminate\Contracts\Container\Container;

final class StatEngine
{
    public function validateInitialHistory(array $epoch, Store $store, Scope $scope): void
    {
        $budget = new HistoryMemoryBudget;
        foreach ($this->reader->events($epoch, $store, $scope) as $event) {
            $budget->check();
        }
    }

    public function __construct(private readonly Container $container, private readonly Stat01Calculator $stat01, private readonly Bt02SignalRegistry $registry,
        private readonly Bt02SignalEligibilityEvaluator $eligibility, private readonly HistoryAggregator $aggregator, private readonly HistoryReader $reader, private readonly RaceStageNormalizer $stages) {}

    public function calculate(array $input, array $epoch, Store $store, Scope $scope, array $sourceRef, ?string $calculatedAt = null): array
    {
        $r = $input['race'];
        $at = Scope::instant($input['input_as_of']);
        $start = $r['scheduled_start_at'] === null ? null : Scope::instant($r['scheduled_start_at']);
        $stat01 = $this->stat01->calculate(new Stat01RaceInputDto($r['original_race_id'], Scope::day($r['race_date']), $r['race_type'], $r['entrant_count'],
            $r['sales_close_at'] === null ? null : Scope::instant($r['sales_close_at']), $start,
            array_map(fn (array $e): Stat01EntryInputDto => new Stat01EntryInputDto($e['original_entry_id'], $e['original_player_id'], $e['bike'], $e['grade'], $e['race_score_raw'], Scope::instant($input['capture_refs'][0]['fetched_at'])), $input['entries'])));
        $byId = [];
        foreach ($stat01->entries as $row) {
            $byId[$row->entry->id] = $row;
        }
        $history = $this->reader->project($input, $epoch, $store, $scope);
        $uuid = 'chronological-'.substr(Json::hash([$sourceRef, $r['external_race_key']]), 0, 32);
        $source = new ArtifactCalculationSource($sourceRef, $uuid);
        $from = Scope::day($epoch['history_event_from']);
        $options2 = new Batch02BuildOptionsDto(null, $from, null, null, $r['original_race_id'], 1, true, $source);
        $options3 = new Batch03BuildOptionsDto(null, $from, null, null, $r['original_race_id'], 1, true, $source);
        $options4 = new Batch04BuildOptionsDto(null, $from, null, null, $r['original_race_id'], 1, true, $source);
        $targets4 = [];
        foreach ($input['entries'] as $e) {
            $s = $byId[$e['original_entry_id']];
            $targets4[] = new Batch04TargetEntryDto($r['original_race_id'], $e['original_entry_id'], $e['original_player_id'], $e['bike'], $e['frame_number'], $at, $start, $s->inputHash,
                $s->features['RACE_SCORE_RAW'], $s->features['RACE_SCORE_RANK'], $s->features['RACE_SCORE_STRENGTH_PERCENTILE'], $r['entrant_count'], count($input['entries']), $input['context']['racetrack_id'], array_column($input['entries'], 'bike'));
        }
        $race4 = new Batch04RaceInputDto($r['original_race_id'], $at, $targets4);
        $entries = $audits = $historyAudit = [];
        foreach ($input['entries'] as $offset => $e) {
            $s = $byId[$e['original_entry_id']];
            $hash = $s->inputHash;
            $target2 = new Batch02TargetEntryDto($r['original_race_id'], $e['original_entry_id'], $e['original_player_id'], $e['bike'], $at, $start ?? $at, $hash, $r['meeting_id']);
            $target3 = new Batch03TargetEntryDto($r['original_race_id'], $e['original_entry_id'], $e['original_player_id'], $e['bike'], $at, $start, $hash,
                $input['context']['racetrack_id'], $input['context']['race_day_id'], $r['meeting_id'], $r['day_number'], (int) Scope::day($r['meeting_start'])->diff(Scope::day($r['meeting_end']))->days + 1,
                $input['context']['meeting_grade'], $input['context']['meeting_day_kind'], $r['race_type'], $r['race_type'], $r['entrant_count'], $this->stages->normalize($r['race_type']));
            $audit = ['STAT-01' => ['calculation_version' => Stat01Calculator::CALCULATION_VERSION, 'status' => $s->status->value, 'quality_status' => $s->qualityStatus->value,
                'features' => $s->features, 'evidence' => $s->evidence, 'input_hash' => $hash, 'source_ref' => $sourceRef, 'source_run_id' => null, 'execution_uuid' => $uuid]];
            $signals = [];
            foreach (['07', '08', '10', '11', '12', '23', '24', '26', '31', '32', '39', '42'] as $code) {
                $calculator = $this->container->make('App\\Domain\\Keirin\\Statistics\\Calculators\\Stat'.$code.'Calculator');
                if (in_array($code, ['10', '11', '12', '24', '26'], true)) {
                    $row = $calculator->calculate($target2, $history['batch02'][$e['original_player_id']] ?? [], $options2, $uuid);
                } elseif (in_array($code, ['39', '42'], true)) {
                    $row = $calculator->calculate($targets4[$offset], $race4, $history['positions'][$e['original_entry_id']], $history['pairs'], $options4, $uuid);
                } else {
                    $row = $calculator->calculate($target3, $history['batch03'][$e['original_player_id']] ?? [], $options3, $uuid);
                }
                $stat = 'STAT-'.$code;
                $primary = $this->registry->primary($stat, $row->features);
                $reasons = $row->evidence['quality_reasons'];
                $eligible = $this->eligibility->eligible($stat, Bt02SignalCohort::Operational,
                    new Bt02SignalFeatureDto($r['original_race_id'], $e['original_entry_id'], $row->status->value, $row->qualityStatus->value, $reasons, $primary));
                $signals[] = $eligible ? $primary : null;
                $audit[$stat] = ['calculation_version' => $calculator->stat()->calculationVersion(), 'status' => $row->status->value, 'quality_status' => $row->qualityStatus->value,
                    'features' => $row->features, 'evidence' => $row->evidence, 'input_hash' => $row->inputHash, 'primary_value' => $primary, 'operational_eligible' => $eligible,
                    'source_ref' => $sourceRef, 'source_run_id' => null, 'execution_uuid' => $uuid];
            }
            $h = $this->aggregator->aggregateChronological(['race_id' => $r['original_race_id'], 'meeting_id' => $r['meeting_id'], 'player_id' => $e['original_player_id'],
                'race_date' => $r['race_date'], 'meeting_start' => $r['meeting_start'], 'meeting_end' => $r['meeting_end'], 'input_as_of' => $input['input_as_of']], $history['c1'][$e['original_player_id']] ?? [], $scope, $epoch['history_event_from']);
            $sd = $s->features['RACE_SCORE_STDDEV_POP'];
            $entries[] = ['id' => $e['original_entry_id'], 'bike' => $e['bike'], 'raw' => $s->features['RACE_SCORE_RAW'], 'stat01_rank' => $s->features['RACE_SCORE_RANK'],
                'anchor' => $sd === 0.0 ? 0.0 : $s->features['RACE_SCORE_Z'], 'anchor_status' => $sd === 0.0 ? 'ZERO_VARIANCE' : 'AVAILABLE',
                'signals' => $signals, 'history' => $h['values'], 'history_status' => $h['status']];
            $audits[$e['original_entry_id']] = $audit;
            $historyAudit[$e['original_entry_id']] = $h;
            foreach ($audits[$e['original_entry_id']] as $code => &$row) {
                $row += ['stat_code' => $code, 'source_run_uuid' => $uuid, 'source_result_id' => null, 'subject_type' => 'RACE_ENTRY',
                    'original_race_id' => $r['original_race_id'], 'original_entry_id' => $e['original_entry_id'], 'original_player_id' => $e['original_player_id'], 'bike' => $e['bike'],
                    'input_as_of' => $input['input_as_of'], 'source_fetched_at' => $input['capture_refs'][0]['fetched_at'], 'calculated_at' => $calculatedAt,
                    'source_kind' => 'ARTIFACT', 'row_ref' => $uuid.':'.$code.':'.$e['original_entry_id'], 'reasons' => $row['evidence']['quality_reasons']];
            }
            unset($row);
        }

        return ['model_input' => ['year' => (int) substr($r['race_date'], 0, 4), 'race_id' => $r['original_race_id'], 'entries' => $entries],
            'stat_sources' => $audits, 'c1_history' => $historyAudit, 'history_used' => $history['used'], 'history_excluded' => $history['excluded'], 'calculation_uuid' => $uuid];
    }
}
