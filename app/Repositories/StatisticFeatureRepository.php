<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\RaceEntrySnapshotDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceCalculationDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\StatFeatureValueDto;
use App\Domain\Keirin\Statistics\Enums\StatFeatureValueType;
use App\Models\Race;
use App\Models\StatFeatureDefinition;
use App\Models\StatFeatureSnapshot;
use App\Models\StatFeatureSource;
use App\Models\StatFeatureValue;
use App\Models\StatisticCalculationRun;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StatisticFeatureRepository
{
    /**
     * @param  callable(Race):void  $callback
     */
    public function eachTargetRace(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?int $raceId,
        int $chunkSize,
        callable $callback,
    ): void {
        $this->targetRaceQuery($from, $to, $raceId)
            ->with(['entries' => fn ($query) => $query->orderBy('id')])
            ->chunkById($chunkSize, function ($races) use ($callback): void {
                foreach ($races as $race) {
                    $callback($race);
                }
            }, 'races.id', 'id');
    }

    public function targetRaceQuery(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?int $raceId,
    ): Builder {
        return Race::query()
            ->select('races.*')
            ->when($raceId !== null, fn (Builder $query): Builder => $query->where('races.id', $raceId))
            ->when($raceId === null && $from !== null, fn (Builder $query): Builder => $query->whereDate('races.race_date', '>=', $from->format('Y-m-d')))
            ->when($raceId === null && $to !== null, fn (Builder $query): Builder => $query->whereDate('races.race_date', '<=', $to->format('Y-m-d')));
    }

    public function assertStat01Definitions(): void
    {
        $stored = StatFeatureDefinition::query()
            ->where('stat_code', Stat01Calculator::STAT_CODE)
            ->where('definition_version', Stat01Calculator::CALCULATION_VERSION)
            ->where('is_active', true)
            ->get()
            ->keyBy('feature_code');
        $expected = Stat01Calculator::featureDefinitions();
        if ($stored->count() !== count($expected)) {
            throw new RuntimeException('STAT-01-v1 feature definitions were missing or inactive.');
        }
        foreach ($expected as $definition) {
            $row = $stored->get($definition['feature_code']);
            if (! $row instanceof StatFeatureDefinition
                || $row->value_type !== $definition['value_type']
                || $row->unit_code !== $definition['unit_code']) {
                throw new RuntimeException("STAT-01 definition {$definition['feature_code']} did not match the calculator.");
            }
        }
    }

    /**
     * @param  list<RaceEntrySnapshotDto>  $entrySnapshots
     */
    public function persistStat01(
        StatisticCalculationRun $run,
        Race $race,
        Stat01RaceInputDto $input,
        Stat01RaceCalculationDto $calculation,
        array $entrySnapshots,
        DateTimeImmutable $calculatedAt,
        bool $recalculate,
    ): void {
        foreach ($calculation->results as $result) {
            $snapshot = $this->findSnapshot($result, $input, $calculation);
            if (! $snapshot instanceof StatFeatureSnapshot) {
                $snapshot = StatFeatureSnapshot::query()->create([
                    'scope_type' => 'RACE_ENTRY',
                    'race_id' => $race->id,
                    'race_entry_id' => $result->raceEntryId,
                    'player_id' => $result->playerId,
                    'opponent_race_entry_id' => null,
                    'opponent_player_id' => null,
                    'stat_code' => Stat01Calculator::STAT_CODE,
                    'input_as_of' => $input->inputAsOf,
                    'input_as_of_policy' => $input->inputAsOfPolicy->value,
                    'input_snapshot_type' => $result->inputSnapshotType,
                    'input_hash' => $calculation->inputHash,
                    'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
                    'status' => $result->status->value,
                    'data_quality_status' => $result->dataQualityStatus->value,
                    'history_start_at' => null,
                    'history_end_at' => null,
                    'sample_count' => $result->validScoreCount,
                    'coverage_rate' => $result->entrantCount === 0
                        ? null
                        : number_format($result->validScoreCount / $result->entrantCount, 6, '.', ''),
                    'source_max_fetched_at' => $this->sourceMaxFetchedAt($input),
                    'calculated_at' => $calculatedAt,
                ]);
                $this->storeValues($snapshot, $result->features);
                $this->storeSources($snapshot, $result, $input, $entrySnapshots, $calculatedAt);
            } elseif ($recalculate) {
                $this->assertValuesMatch($snapshot, $result->features);
            }

            DB::table('statistic_run_feature_snapshots')->insertOrIgnore([
                'calculation_run_id' => $run->id,
                'stat_feature_snapshot_id' => $snapshot->id,
                'race_id' => $race->id,
                'created_at' => $calculatedAt,
            ]);
            $this->storeRunOccurrences(
                $run,
                $snapshot,
                $race,
                $result,
                $entrySnapshots,
                $calculatedAt,
            );
        }
    }

    private function findSnapshot(
        Stat01EntryResultDto $result,
        Stat01RaceInputDto $input,
        Stat01RaceCalculationDto $calculation,
    ): ?StatFeatureSnapshot {
        return StatFeatureSnapshot::query()
            ->where('scope_type', 'RACE_ENTRY')
            ->where('race_entry_id', $result->raceEntryId)
            ->where('stat_code', Stat01Calculator::STAT_CODE)
            ->when(
                $input->inputAsOf === null,
                fn (Builder $query): Builder => $query->whereNull('input_as_of'),
                fn (Builder $query): Builder => $query->where('input_as_of', $input->inputAsOf),
            )
            ->where('calculation_version', Stat01Calculator::CALCULATION_VERSION)
            ->where('input_hash', $calculation->inputHash)
            ->first();
    }

    /**
     * @param  list<StatFeatureValueDto>  $features
     */
    private function storeValues(StatFeatureSnapshot $snapshot, array $features): void
    {
        foreach ($features as $feature) {
            StatFeatureValue::query()->create([
                'stat_feature_snapshot_id' => $snapshot->id,
                ...$this->valueAttributes($feature),
            ]);
        }
    }

    /**
     * @param  list<RaceEntrySnapshotDto>  $entrySnapshots
     */
    private function storeSources(
        StatFeatureSnapshot $featureSnapshot,
        Stat01EntryResultDto $result,
        Stat01RaceInputDto $input,
        array $entrySnapshots,
        DateTimeImmutable $createdAt,
    ): void {
        foreach ($entrySnapshots as $source) {
            if ($source->id === null) {
                throw new RuntimeException('Persisted STAT-01 features require persisted race-entry snapshots.');
            }
            StatFeatureSource::query()->create([
                'stat_feature_snapshot_id' => $featureSnapshot->id,
                'race_entry_snapshot_id' => $source->id,
                'scraping_fetch_log_id' => $source->scrapingFetchLogId,
                'source_role' => $source->raceEntryId === $result->raceEntryId ? 'PRIMARY_INPUT' : 'CONTEXT_INPUT',
                'source_identity_key' => $source->sourceIdentityKey,
                'source_type' => 'RACE_ENTRY_SNAPSHOT',
                'source_url' => $source->sourceUrl,
                'raw_file_path' => $source->rawFilePath,
                'raw_sha256' => $source->rawSha256,
                'source_fetched_at' => $source->observedAt,
                'source_reference_at' => $input->inputAsOf,
                'parser_version' => $source->parserVersion,
                'source_timing_status' => $this->sourceTimingStatus($source, $input),
                'created_at' => $createdAt,
            ]);
        }
    }

    /**
     * @param  list<RaceEntrySnapshotDto>  $entrySnapshots
     */
    private function storeRunOccurrences(
        StatisticCalculationRun $run,
        StatFeatureSnapshot $featureSnapshot,
        Race $race,
        Stat01EntryResultDto $result,
        array $entrySnapshots,
        DateTimeImmutable $createdAt,
    ): void {
        foreach ($entrySnapshots as $source) {
            if ($source->occurrenceId === null) {
                throw new RuntimeException(
                    'Persisted STAT-01 runs require persisted race-entry snapshot occurrences.',
                );
            }

            DB::table('statistic_run_feature_snapshot_occurrences')->insertOrIgnore([
                'calculation_run_id' => $run->id,
                'stat_feature_snapshot_id' => $featureSnapshot->id,
                'race_entry_snapshot_occurrence_id' => $source->occurrenceId,
                'race_id' => $race->id,
                'race_entry_id' => $source->raceEntryId,
                'source_role' => $source->raceEntryId === $result->raceEntryId
                    ? 'PRIMARY_INPUT'
                    : 'CONTEXT_INPUT',
                'created_at' => $createdAt,
            ]);
        }
    }

    private function sourceTimingStatus(RaceEntrySnapshotDto $source, Stat01RaceInputDto $input): string
    {
        if ($source->observedAt === null) {
            return 'UNKNOWN_SOURCE_TIMING';
        }
        if ($source->sourceLinkMissing) {
            return 'SOURCE_LINK_MISSING';
        }
        if ($input->inputAsOf === null) {
            return 'INPUT_AS_OF_UNAVAILABLE';
        }

        return $source->observedAt <= $input->inputAsOf
            ? 'AT_OR_BEFORE_INPUT_AS_OF'
            : 'HISTORICAL_AFTER_INPUT_AS_OF';
    }

    private function sourceMaxFetchedAt(Stat01RaceInputDto $input): ?DateTimeImmutable
    {
        $maximum = null;
        foreach ($input->entries as $entry) {
            if ($entry->fetchedAt !== null && ($maximum === null || $entry->fetchedAt > $maximum)) {
                $maximum = $entry->fetchedAt;
            }
        }

        return $maximum;
    }

    /**
     * @param  list<StatFeatureValueDto>  $features
     */
    private function assertValuesMatch(StatFeatureSnapshot $snapshot, array $features): void
    {
        $stored = $snapshot->values()->get()->mapWithKeys(
            fn (StatFeatureValue $value): array => [$this->valueKey($value->feature_code, $value->window_type, $value->window_value) => $this->storedValueAttributes($value)],
        )->all();
        $recalculated = [];
        foreach ($features as $feature) {
            $attributes = $this->valueAttributes($feature);
            $recalculated[$this->valueKey($feature->featureCode, $feature->windowType, $feature->windowValue)] = $this->comparableAttributes($attributes);
        }
        ksort($stored);
        ksort($recalculated);
        if ($stored !== $recalculated) {
            throw new RuntimeException("STAT-01 recalculation differed for feature snapshot {$snapshot->id}; calculation_version must change.");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function valueAttributes(StatFeatureValueDto $feature): array
    {
        $attributes = [
            'feature_code' => $feature->featureCode,
            'value_type' => $feature->valueType->value,
            'feature_value_integer' => null,
            'feature_value_numeric' => null,
            'feature_value_text' => null,
            'feature_value_boolean' => null,
            'feature_value_json' => null,
            'numerator' => $feature->numerator,
            'denominator' => $feature->denominator,
            'sample_count' => $feature->sampleCount,
            'window_type' => $feature->windowType,
            'window_value' => $feature->windowValue,
            'unit_code' => $feature->unitCode,
            'status' => $feature->status->value,
        ];
        match ($feature->valueType) {
            StatFeatureValueType::Integer => $attributes['feature_value_integer'] = $feature->value,
            StatFeatureValueType::Numeric => $attributes['feature_value_numeric'] = $feature->value,
            StatFeatureValueType::Text => $attributes['feature_value_text'] = $feature->value,
            StatFeatureValueType::Boolean => $attributes['feature_value_boolean'] = $feature->value,
            StatFeatureValueType::Json => $attributes['feature_value_json'] = $feature->value,
        };

        return $attributes;
    }

    /**
     * @return array<string,mixed>
     */
    private function storedValueAttributes(StatFeatureValue $value): array
    {
        return $this->comparableAttributes([
            'feature_code' => $value->feature_code,
            'value_type' => $value->value_type,
            'feature_value_integer' => $value->feature_value_integer,
            'feature_value_numeric' => $value->feature_value_numeric,
            'feature_value_text' => $value->feature_value_text,
            'feature_value_boolean' => $value->feature_value_boolean,
            'feature_value_json' => $value->feature_value_json,
            'numerator' => $value->numerator,
            'denominator' => $value->denominator,
            'sample_count' => $value->sample_count,
            'window_type' => $value->window_type,
            'window_value' => $value->window_value,
            'unit_code' => $value->unit_code,
            'status' => $value->status,
        ]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function comparableAttributes(array $attributes): array
    {
        foreach (['feature_value_numeric', 'numerator', 'denominator'] as $key) {
            if ($attributes[$key] !== null) {
                $attributes[$key] = round((float) $attributes[$key], 10);
            }
        }
        if ($attributes['feature_value_integer'] !== null) {
            $attributes['feature_value_integer'] = (int) $attributes['feature_value_integer'];
        }
        if ($attributes['sample_count'] !== null) {
            $attributes['sample_count'] = (int) $attributes['sample_count'];
        }
        if ($attributes['feature_value_boolean'] !== null) {
            $attributes['feature_value_boolean'] = (bool) $attributes['feature_value_boolean'];
        }

        return $attributes;
    }

    private function valueKey(string $featureCode, ?string $windowType, ?string $windowValue): string
    {
        return $featureCode.'|'.($windowType ?? '').'|'.($windowValue ?? '');
    }
}
