<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\DTO\Stat01TargetCountsDto;
use App\Domain\Keirin\Statistics\DTO\Stat20AcquiredHistoryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20AcquiredHistoryOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat20RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20SourceInputDto;
use App\Domain\Keirin\Statistics\Support\AcquiredHistoryLineage;
use App\Domain\Keirin\Statistics\Support\AcquiredHistoryTimestamp;
use App\Domain\Keirin\Statistics\Support\Stat20AcquiredHistoryContract as Contract;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class Stat20AcquiredHistoryInputRepository
{
    /** @return array<string,StatisticFeatureRun> */
    public function selectedRuns(Stat20AcquiredHistoryOptionsDto $options): array
    {
        $versions = Contract::versions($options->sourceContract);
        $runs = [];
        foreach ([$options->stat01RunId, ...$options->sourceRunIds] as $id) {
            $run = StatisticFeatureRun::query()->find($id);
            if ($run === null || ! isset($versions[$run->stat_code])
                || $run->calculation_version !== $versions[$run->stat_code]
                || $run->mode !== 'BACKFILL' || ! in_array($run->status, ['SUCCEEDED', 'PARTIALLY_SUCCEEDED'], true)
                || $run->finished_at === null || isset($runs[$run->stat_code])
                || ($id === $options->stat01RunId && ($run->stat_code !== 'STAT-01' || (int) $run->error_count !== 0
                    || (int) $run->processed_race_count !== (int) $run->target_race_count))
                || ($id !== $options->stat01RunId && ($run->parameters['stat01_run_id'] ?? null) !== $options->stat01RunId)) {
                throw new UnexpectedValueException('STAT-20 v2 unsupported/duplicate/unfinished source or STAT01 lineage mismatch.');
            }
            if (in_array($run->stat_code, Contract::NEW_STATS, true)
                && (($run->parameters['lineage_contract'] ?? null) !== AcquiredHistoryLineage::CONTRACT
                    || ($run->parameters['build_service_version'] ?? null) !== Contract::BUILD_SERVICE_VERSION)) {
                throw new UnexpectedValueException('STAT-20 v2 saved lineage contract unsupported.');
            }
            if ($run->stat_code === 'STAT-09' && ! Contract::stat09MetadataMatches($options->sourceContract, $run->parameters)) {
                throw new UnexpectedValueException('STAT09 run profile/projection metadata mismatch.');
            }
            if ($id === $options->stat01RunId && DB::table('statistic_feature_results')->where('feature_run_id', $id)
                ->where('stat_code', 'STAT-01')->where('subject_type', 'RACE_ENTRY')->count() !== (int) $run->target_entry_count) {
                throw new UnexpectedValueException('STAT-20 v2 incomplete STAT01 result snapshot.');
            }
            $runs[$run->stat_code] = $run;
        }
        ksort($runs);

        return $runs;
    }

    public function counts(Stat20AcquiredHistoryOptionsDto $options): Stat01TargetCountsDto
    {
        $q = $this->targets($options)->join('statistic_feature_results as s', 's.race_id', '=', 'races.id')
            ->where('s.feature_run_id', $options->stat01RunId)->where('s.stat_code', 'STAT-01')->where('s.subject_type', 'RACE_ENTRY');

        return new Stat01TargetCountsDto((int) (clone $q)->distinct()->count('races.id'), $q->count('s.id'));
    }

    public function raceIds(Stat20AcquiredHistoryOptionsDto $options): \Generator
    {
        foreach ($this->targets($options)->select('races.id')->lazyById($options->chunkSize, 'races.id', 'id') as $r) {
            yield (int) $r->id;
        }
    }

    public function raceInput(Stat20AcquiredHistoryOptionsDto $options, array $runs, int $id): Stat20AcquiredHistoryInputDto
    {
        $race = $this->targets($options)->where('races.id', $id)->select(['races.race_date', 'races.scheduled_start_at'])->first();
        if ($race === null) {
            throw new UnexpectedValueException('STAT-20 v2 target missing or outside fixed scope.');
        }
        $ids = array_map(fn ($r) => (int) $r->id, $runs);
        $rows = DB::table('statistic_feature_results')->where('race_id', $id)->whereIntegerInRaw('feature_run_id', array_values($ids))
            ->orderBy('id')->limit(Contract::MAX_SOURCE_RESULTS + 1)->get();
        if ($rows->count() > Contract::MAX_SOURCE_RESULTS) {
            throw new UnexpectedValueException('STAT-20 v2 source result limit exceeded.');
        }
        $sources = $entries = [];
        foreach ($rows as $r) {
            if ($r->acquisition_mode !== 'BACKFILL') {
                throw new UnexpectedValueException('STAT-20 v2 source acquisition mode mismatch.');
            }
            $sources[] = new Stat20SourceInputDto((int) $r->id, (int) $r->feature_run_id, $r->stat_code, $r->calculation_version,
                $r->subject_type, $r->subject_key, (int) $r->race_id, $r->race_entry_id === null ? null : (int) $r->race_entry_id,
                $r->player_id === null ? null : (int) $r->player_id, $r->bike_number === null ? null : (int) $r->bike_number,
                $r->status, $r->quality_status, $r->input_as_of === null ? null : AcquiredHistoryTimestamp::parse($r->input_as_of),
                $r->source_fetched_at === null ? null : AcquiredHistoryTimestamp::parse($r->source_fetched_at),
                $this->object($r->features), $this->object($r->evidence), $r->input_hash);
            if ($r->stat_code === 'STAT-01' && $r->race_entry_id !== null) {
                $entries[] = (int) $r->race_entry_id;
            }
        }
        $identities = [];
        foreach (DB::table('race_entries')->where('race_id', $id)->whereIntegerInRaw('id', $entries)
            ->select(['id', 'race_id', 'player_id', 'bike_number'])->get() as $e) {
            $identities[(int) $e->id] = ['race_id' => (int) $e->race_id, 'player_id' => $e->player_id === null ? null : (int) $e->player_id,
                'bike_number' => (int) $e->bike_number];
        }
        $audit = [];
        foreach ($runs as $code => $run) {
            $item = DB::table('statistic_feature_run_items')->where('feature_run_id', $run->id)->where('race_id', $id)->first();
            $audit[$code] = ['run_id' => (int) $run->id, 'status' => $run->status, 'version' => $run->calculation_version,
                'parameters' => $run->parameters, 'error_count' => (int) $run->error_count,
                'error_summary' => $run->error_summary === null ? null : json_decode($run->error_summary, true, flags: JSON_THROW_ON_ERROR),
                'processed_race_count' => (int) $run->processed_race_count, 'target_race_count' => (int) $run->target_race_count,
                'race_item' => $item === null ? null : (array) $item];
        }

        return new Stat20AcquiredHistoryInputDto(new Stat20RaceInputDto($id, new DateTimeImmutable($race->race_date, new DateTimeZone('Asia/Tokyo')),
            $race->scheduled_start_at === null ? null : AcquiredHistoryTimestamp::parse($race->scheduled_start_at), $ids, $sources, $identities), $audit, $options->sourceContract);
    }

    private function targets(Stat20AcquiredHistoryOptionsDto $options): Builder
    {
        return DB::table('races')->whereBetween('races.race_date', ['2022-01-01', '2025-12-31'])
            ->when($options->raceId !== null, fn ($q) => $q->where('races.id', $options->raceId),
                fn ($q) => $q->whereBetween('races.race_date', [$options->from->format('Y-m-d'), $options->to->format('Y-m-d')]))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('statistic_feature_results as t')->whereColumn('t.race_id', 'races.id')
                ->where('t.feature_run_id', $options->stat01RunId)->where('t.stat_code', 'STAT-01')->where('t.subject_type', 'RACE_ENTRY'));
    }

    private function object(string $json): array
    {
        if (! json_decode($json, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass) {
            throw new UnexpectedValueException('STAT-20 v2 source JSON must be an object.');
        }

        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }
}
