<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline;

use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryReader;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02SignalFeatureRepository;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RaceInputSource
{
    public function __construct(private readonly ReadOnlySession $session, private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02SourceManifest $signalManifest, private readonly BacktestFeatureRepository $baseline,
        private readonly Bt02SignalFeatureRepository $signals, private readonly Bt02SignalEligibilityEvaluator $eligibility,
        private readonly HistoryReader $history, private readonly HistoryAggregator $aggregator, private readonly RaceCategoryPolicy $categories) {}

    public function capture(Request $request): array
    {
        return $this->session->run(fn (array $settings): array => $this->read($request) + ['read_only' => $settings]);
    }

    private function read(Request $request): array
    {
        $race = DB::table('races as r')->join('race_days as d', 'd.id', '=', 'r.race_day_id')
            ->join('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')
            ->where('r.id', $request->raceId)->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])
            ->select(['r.id as race_id', 'r.race_date', 'r.race_number', 'r.race_type', 'r.entrant_count', 'r.scheduled_start_at', 'r.sales_close_at',
                'd.id as race_day_id', 'd.race_date as day_date', 'd.day_number', 'm.id as meeting_id', 'm.starts_on as meeting_start', 'm.ends_on as meeting_end'])->first();
        if ($race === null || $race->race_date !== $race->day_date || $this->categories->classify($race->race_type) !== RaceCategory::Men) {
            throw new RuntimeException('Target development race metadata was unavailable or inconsistent.');
        }
        $scheduled = Request::timestamp($race->scheduled_start_at);
        $cutoff = $race->sales_close_at === null ? $scheduled : Request::timestamp($race->sales_close_at);
        if ($scheduled->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d') !== $race->race_date
            || $cutoff > $scheduled || $request->asOf > $cutoff) {
            throw new RuntimeException('Requested input_as_of or race cutoff was inconsistent.');
        }
        $year = (int) substr($race->race_date, 0, 4);
        $entries = DB::table('race_entries as e')->join('races as r', 'r.id', '=', 'e.race_id')->where('e.race_id', $request->raceId)
            ->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])->orderBy('e.id')
            ->get(['e.id', 'e.race_id', 'e.player_id', 'e.bike_number', 'e.external_player_id'])->map(fn ($r) => (array) $r)->all();
        if (count($entries) !== (int) $race->entrant_count || count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('Target entrant count disagreed.');
        }
        $byId = $bikes = [];
        foreach ($entries as &$entry) {
            foreach (['id', 'race_id', 'player_id', 'bike_number'] as $key) {
                if ($entry[$key] === null || ! ctype_digit((string) $entry[$key]) || (int) $entry[$key] < 1) {
                    throw new RuntimeException('Target entrant identity was unresolved.');
                }
                $entry[$key] = (int) $entry[$key];
            }
            if ($entry['bike_number'] > 9 || isset($byId[$entry['id']]) || isset($bikes[$entry['bike_number']])) {
                throw new RuntimeException('Target entrant identity was duplicated or out of range.');
            }
            $byId[$entry['id']] = $entry;
            $bikes[$entry['bike_number']] = true;
        }
        unset($entry);
        $fixed = ['STAT-01' => $this->baselineManifest->forYear($year)];
        foreach (Bt03e03Contract::STAT_CODES as $code) {
            $fixed[$code] = $this->signalManifest->for($year, $code);
        }
        $audit = ['metadata' => (array) $race, 'entries' => $entries, 'cutoff_source' => $race->sales_close_at === null ? 'SCHEDULED_START_AT_FALLBACK' : 'SALES_CLOSE_AT',
            'publication_time_verified' => 'UNKNOWN', 'runs' => [], 'features' => [], 'history' => []];
        foreach ($fixed as $code => $expected) {
            $run = DB::table('statistic_feature_runs')->where('id', $expected->featureRunId)->first([
                'id', 'run_uuid', 'stat_code', 'calculation_version', 'mode', 'status', 'target_from', 'target_to',
                'target_race_count', 'processed_race_count', 'target_entry_count', 'error_count', 'started_at', 'finished_at']);
            $version = $code === 'STAT-01' ? Bt01SourceManifest::CALCULATION_VERSION : $expected->calculationVersion;
            if ($run === null || $run->run_uuid !== $expected->featureRunUuid || $run->stat_code !== $code
                || $run->calculation_version !== $version || $run->mode !== 'BACKFILL'
                || ! in_array($run->status, ['SUCCEEDED', 'PARTIALLY_SUCCEEDED'], true) || (int) $run->error_count !== 0
                || $run->target_from !== $expected->targetFrom || $run->target_to !== $expected->targetTo
                || (int) $run->processed_race_count !== ($code === 'STAT-01' ? $expected->expectedRaceCount : $expected->processedRaceCount)
                || (int) $run->target_entry_count !== ($code === 'STAT-01' ? $expected->expectedResultCount : $expected->targetEntryCount)) {
                throw new RuntimeException('Fixed source run identity disagreed: '.$code);
            }
            $audit['runs'][$code] = ['manifest' => $expected->canonical(), 'observed' => (array) $run];
            // The signal DTO omits timing/hash. Read only this race's fixed rows for audit.
            $rows = DB::table('statistic_feature_results as f')->join('races as r', 'r.id', '=', 'f.race_id')
                ->where('f.feature_run_id', $expected->featureRunId)->where('f.race_id', $request->raceId)
                ->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])->orderBy('f.race_entry_id')->get([
                    'f.id', 'f.feature_run_id', 'f.stat_code', 'f.calculation_version', 'f.subject_type', 'f.race_id', 'f.race_entry_id', 'f.player_id',
                    'f.bike_number', 'f.status', 'f.quality_status', 'f.acquisition_mode', 'f.input_as_of', 'f.source_fetched_at',
                    'f.calculated_at', 'f.input_hash', 'f.features', 'f.evidence']);
            $seen = [];
            foreach ($rows as $row) {
                $id = (int) $row->race_entry_id;
                $target = $byId[$id] ?? null;
                if ($target === null || isset($seen[$id]) || (int) $row->player_id !== $target['player_id']
                    || (int) $row->bike_number !== $target['bike_number'] || $row->stat_code !== $code || $row->calculation_version !== $version
                    || $row->subject_type !== 'RACE_ENTRY' || $row->acquisition_mode !== 'BACKFILL'
                    || StatisticFeatureResultStatus::tryFrom($row->status) === null
                    || StatisticQualityStatus::tryFrom($row->quality_status) === null
                    || preg_match('/\A[0-9a-f]{64}\z/', $row->input_hash) !== 1) {
                    throw new RuntimeException('Fixed feature identity/status disagreed: '.$code);
                }
                $asOf = Request::timestamp($row->input_as_of);
                if ($asOf > $request->asOf || ($code === 'STAT-01' && $asOf != $cutoff)) {
                    throw new RuntimeException('Feature input_as_of exceeded request or disagreed with cutoff: '.$code);
                }
                Request::timestamp($row->calculated_at);
                if ($row->source_fetched_at !== null) {
                    Request::timestamp($row->source_fetched_at);
                }
                $seen[$id] = true;
            }
            if (count($seen) !== count($byId)) {
                throw new RuntimeException('Fixed feature entrant set disagreed: '.$code);
            }
            $audit['features'][$code] = $rows->map(fn ($row) => (array) $row)->all();
        }
        $base = $this->baseline->forRaces($fixed['STAT-01']->featureRunId, [$request->raceId])[$request->raceId] ?? [];
        if (array_column($base, 'raceEntryId') !== array_keys($byId)) {
            throw new RuntimeException('STAT-01 DTO entrant order/set disagreed.');
        }
        $signals = [];
        foreach (Bt03e03Contract::STAT_CODES as $code) {
            foreach ($this->signals->forRaces($fixed[$code]->featureRunId, $code, [$request->raceId])[$request->raceId] ?? [] as $feature) {
                $signals[$code][$feature->raceEntryId] = $feature;
            }
        }
        $raws = [];
        foreach ($base as $feature) {
            if ($feature->status !== 'VALID' || $feature->qualityStatus !== 'FULL' || ! $feature->raceScoreAvailable
                || $feature->raceScoreRaw === null || ! is_numeric($feature->raceScoreRaw) || ! is_finite((float) $feature->raceScoreRaw)
                || (float) $feature->raceScoreRaw <= 0 || $feature->raceScoreRank === null || $feature->raceScoreRank < 1) {
                throw new RuntimeException('STAT-01 anchor was unavailable.');
            }
            $raws[] = (float) $feature->raceScoreRaw;
        }
        $mean = array_sum($raws) / count($raws);
        $sd = sqrt(array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $raws)) / count($raws));
        $output = [];
        foreach ($base as $feature) {
            $values = [];
            foreach (Bt03e03Contract::STAT_CODES as $code) {
                $signal = $signals[$code][$feature->raceEntryId] ?? throw new RuntimeException('Signal DTO was missing.');
                $values[] = $this->eligibility->eligible($code, Bt02SignalCohort::Operational, $signal) ? $signal->primaryValue : null;
            }
            $target = ['race_id' => $request->raceId, 'meeting_id' => (int) $race->meeting_id, 'player_id' => $feature->playerId,
                'race_date' => $race->race_date, 'meeting_start' => $race->meeting_start, 'meeting_end' => $race->meeting_end,
                'input_as_of' => $feature->inputAsOf->format(DATE_ATOM)];
            $empty = $this->aggregator->aggregate($target, []);
            $hash = hash_init('sha256');
            $source = (function () use ($target, $hash): \Generator {
                foreach ($this->history->rows($target['player_id'], $target['meeting_start'], $target['meeting_id']) as $row) {
                    hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
                    yield $row;
                }
            })();
            $aggregate = $empty['status'] === 'NO_HISTORY' ? $this->aggregator->aggregate($target, $source) : $empty;
            $audit['history'][] = ['target' => $target, 'source_sha256' => hash_final($hash), 'aggregate' => $aggregate];
            $output[] = ['id' => $feature->raceEntryId, 'bike' => $feature->bikeNumber, 'raw' => (float) $feature->raceScoreRaw,
                'stat01_rank' => $feature->raceScoreRank, 'anchor' => $sd > 0.0 ? ((float) $feature->raceScoreRaw - $mean) / $sd : 0.0,
                'anchor_status' => $sd > 0.0 ? 'AVAILABLE' : 'ZERO_VARIANCE', 'signals' => $values,
                'history' => $aggregate['values'], 'history_status' => $aggregate['status']];
        }

        return ['input' => ['year' => $year, 'race_id' => $request->raceId, 'entries' => $output], 'audit' => $audit];
    }
}
