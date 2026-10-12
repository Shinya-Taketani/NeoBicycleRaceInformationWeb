<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\Calculators\Stat20Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat01TargetCountsDto;
use App\Domain\Keirin\Statistics\DTO\Stat20BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat20RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20SourceInputDto;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

class Stat20InputRepository
{
    /** @return array<string,int> */
    public function sourceRuns(Stat20BuildOptionsDto $options): array
    {
        $runs = [];
        foreach ([$options->stat01RunId, ...$options->sourceRunIds] as $id) {
            $run = StatisticFeatureRun::query()->find($id);
            if (! $run instanceof StatisticFeatureRun
                || ! in_array($run->stat_code, Stat20Calculator::SOURCE_STAT_CODES, true)
                || $run->calculation_version !== $run->stat_code.'-existing-db-v1'
                || $run->mode !== 'BACKFILL'
                || ! in_array($run->status, ['SUCCEEDED', 'PARTIALLY_SUCCEEDED'], true)
                || $run->finished_at === null || isset($runs[$run->stat_code])
                || ($id === $options->stat01RunId && ($run->stat_code !== 'STAT-01'
                    || (int) $run->processed_race_count !== (int) $run->target_race_count || (int) $run->error_count !== 0))
                || ($id !== $options->stat01RunId && ($run->parameters['stat01_run_id'] ?? null) !== $options->stat01RunId)) {
                throw new RuntimeException('STAT-20 source run is incomplete, unsupported, duplicate, or belongs to another snapshot.');
            }
            $runs[$run->stat_code] = (int) $id;
        }
        ksort($runs);

        return $runs;
    }

    public function counts(Stat20BuildOptionsDto $options): Stat01TargetCountsDto
    {
        $query = $this->targets($options)->join('statistic_feature_results as s', 's.race_id', '=', 'races.id')
            ->where('s.feature_run_id', $options->stat01RunId)->where('s.stat_code', 'STAT-01')->where('s.subject_type', 'RACE_ENTRY');

        return new Stat01TargetCountsDto((int) (clone $query)->distinct()->count('races.id'), (int) $query->count('s.id'));
    }

    public function raceIds(Stat20BuildOptionsDto $options): \Generator
    {
        foreach ($this->targets($options)->select('races.id')->lazyById($options->chunkSize, 'races.id', 'id') as $row) {
            yield (int) $row->id;
        }
    }

    /** @param array<string,int> $runs */
    public function raceInput(Stat20BuildOptionsDto $options, array $runs, int $raceId): Stat20RaceInputDto
    {
        $race = $this->targets($options)->where('races.id', $raceId)
            ->select(['races.id', 'races.race_date', 'races.scheduled_start_at'])->first();
        if ($race === null) {
            throw new UnexpectedValueException('STAT-20 target disappeared or is outside the permitted cohort.');
        }
        $rows = DB::table('statistic_feature_results')->where('race_id', $raceId)
            ->whereIntegerInRaw('feature_run_id', array_values($runs))->orderBy('id')->limit(136)->get();
        if ($rows->count() > 135) {
            throw new UnexpectedValueException('STAT-20 source subject count exceeds the bounded contract.');
        }
        $sources = [];
        $entries = [];
        foreach ($rows as $row) {
            if ($row->acquisition_mode !== 'BACKFILL') {
                throw new UnexpectedValueException('STAT-20 source acquisition mode mismatch.');
            }
            $sources[] = new Stat20SourceInputDto(
                (int) $row->id, (int) $row->feature_run_id, $row->stat_code, $row->calculation_version,
                $row->subject_type, $row->subject_key, (int) $row->race_id,
                $row->race_entry_id !== null ? (int) $row->race_entry_id : null,
                $row->player_id !== null ? (int) $row->player_id : null,
                $row->bike_number !== null ? (int) $row->bike_number : null,
                $row->status, $row->quality_status, $this->date($row->input_as_of), $this->date($row->source_fetched_at),
                $this->object($row->features), $this->object($row->evidence), $row->input_hash,
            );
            if ($row->stat_code === 'STAT-01' && $row->race_entry_id !== null) {
                $entries[] = (int) $row->race_entry_id;
            }
        }
        $identities = [];
        foreach (DB::table('race_entries')->where('race_id', $raceId)->whereIntegerInRaw('id', $entries)
            ->select(['id', 'race_id', 'player_id', 'bike_number'])->orderBy('id')->get() as $entry) {
            $identities[(int) $entry->id] = ['race_id' => (int) $entry->race_id,
                'player_id' => $entry->player_id !== null ? (int) $entry->player_id : null,
                'bike_number' => (int) $entry->bike_number];
        }

        return new Stat20RaceInputDto($raceId, new DateTimeImmutable($race->race_date), $this->date($race->scheduled_start_at),
            $runs, $sources, $identities);
    }

    private function targets(Stat20BuildOptionsDto $options): Builder
    {
        return DB::table('races')->where('races.race_date', '<', '2026-01-01')
            ->when($options->raceId !== null, fn ($q) => $q->where('races.id', $options->raceId),
                fn ($q) => $q->whereDate('races.race_date', '>=', $options->from?->format('Y-m-d'))
                    ->whereDate('races.race_date', '<=', $options->to?->format('Y-m-d')))
            ->whereExists(function (Builder $query) use ($options): void {
                $query->selectRaw('1')->from('statistic_feature_results as target')
                    ->whereColumn('target.race_id', 'races.id')->where('target.feature_run_id', $options->stat01RunId)
                    ->where('target.stat_code', 'STAT-01')->where('target.subject_type', 'RACE_ENTRY');
            });
    }

    private function object(string $json): array
    {
        if (! json_decode($json, false, 64, JSON_THROW_ON_ERROR) instanceof stdClass) {
            throw new UnexpectedValueException('STAT-20 source JSON must be an object.');
        }

        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value) !== 1) {
            throw new UnexpectedValueException('STAT-20 source timestamp is invalid.');
        }
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) {
            throw new UnexpectedValueException('STAT-20 source timestamp is invalid.');
        }

        return $date;
    }
}
