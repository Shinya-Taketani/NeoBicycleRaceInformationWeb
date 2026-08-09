<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\FeatureInputDto;
use App\Domain\Keirin\Backtest\DTO\SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\DTO\VerifiedSourceDto;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BacktestFeatureRepository
{
    /** @param list<SourceManifestEntryDto> $manifest @return list<VerifiedSourceDto> */
    public function validateSources(array $manifest): array
    {
        $ids = array_map(fn (SourceManifestEntryDto $entry): int => $entry->featureRunId, $manifest);
        $runs = DB::table('statistic_feature_runs')->whereIn('id', $ids)->get()->keyBy('id');
        $counts = DB::table('statistic_feature_results')
            ->selectRaw('feature_run_id, COUNT(*) AS result_count, COUNT(DISTINCT race_id) AS race_count')
            ->selectRaw('SUM(CASE WHEN stat_code <> ? THEN 1 ELSE 0 END) AS invalid_stat_count', [Bt01SourceManifest::STAT_CODE])
            ->selectRaw('SUM(CASE WHEN calculation_version <> ? THEN 1 ELSE 0 END) AS invalid_version_count', [Bt01SourceManifest::CALCULATION_VERSION])
            ->selectRaw("SUM(CASE WHEN subject_type <> 'RACE_ENTRY' THEN 1 ELSE 0 END) AS invalid_subject_count")
            ->whereIn('feature_run_id', $ids)
            ->groupBy('feature_run_id')
            ->get()
            ->keyBy('feature_run_id');
        $verified = [];

        foreach ($manifest as $expected) {
            $run = $runs->get($expected->featureRunId);
            $aggregate = $counts->get($expected->featureRunId);
            $resultCount = (int) ($aggregate->result_count ?? 0);
            $raceCount = (int) ($aggregate->race_count ?? 0);
            $valid = $run !== null
                && (string) $run->run_uuid === $expected->featureRunUuid
                && (string) $run->stat_code === Bt01SourceManifest::STAT_CODE
                && (string) $run->calculation_version === Bt01SourceManifest::CALCULATION_VERSION
                && (string) $run->target_from === $expected->targetFrom
                && (string) $run->target_to === $expected->targetTo
                && (int) $run->target_race_count === $expected->expectedRaceCount
                && (int) $run->processed_race_count === $expected->expectedRaceCount
                && (int) $run->target_entry_count === $expected->expectedResultCount
                && (int) $run->error_count === 0
                && (string) $run->status === 'PARTIALLY_SUCCEEDED'
                && $resultCount === $expected->expectedResultCount
                && $raceCount === $expected->expectedRaceCount
                && (int) ($aggregate->invalid_stat_count ?? 0) === 0
                && (int) ($aggregate->invalid_version_count ?? 0) === 0
                && (int) ($aggregate->invalid_subject_count ?? 0) === 0;
            if (! $valid) {
                throw new RuntimeException("Fixed STAT-01 source run {$expected->featureRunId} was invalid.");
            }
            $verified[] = new VerifiedSourceDto($expected, $raceCount, $resultCount);
        }

        return $verified;
    }

    /** @param list<int> $raceIds @return array<int, list<FeatureInputDto>> */
    public function forRaces(int $featureRunId, array $raceIds): array
    {
        if ($raceIds === []) {
            return [];
        }
        $raw = $this->jsonText('features', 'RACE_SCORE_RAW');
        $available = $this->jsonText('features', 'RACE_SCORE_AVAILABLE');
        $rank = $this->jsonText('features', 'RACE_SCORE_RANK');
        $rows = DB::table('statistic_feature_results')
            ->select(['id', 'feature_run_id', 'race_id', 'race_entry_id', 'player_id', 'bike_number', 'status', 'quality_status', 'input_as_of', 'input_hash'])
            ->selectRaw("{$raw} AS race_score_raw")
            ->selectRaw("{$available} AS race_score_available")
            ->selectRaw("{$rank} AS race_score_rank")
            ->where('feature_run_id', $featureRunId)
            ->whereIn('race_id', $raceIds)
            ->orderBy('race_id')
            ->orderBy('race_entry_id')
            ->get();
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->race_id][] = new FeatureInputDto(
                id: (int) $row->id,
                featureRunId: (int) $row->feature_run_id,
                raceId: (int) $row->race_id,
                raceEntryId: (int) $row->race_entry_id,
                playerId: $row->player_id !== null ? (int) $row->player_id : null,
                bikeNumber: (int) $row->bike_number,
                status: (string) $row->status,
                qualityStatus: (string) $row->quality_status,
                inputAsOf: $row->input_as_of !== null ? new DateTimeImmutable((string) $row->input_as_of) : null,
                inputHash: (string) $row->input_hash,
                raceScoreRaw: $row->race_score_raw !== null ? (string) $row->race_score_raw : null,
                raceScoreAvailable: in_array($row->race_score_available, [true, 1, '1', 'true'], true),
                raceScoreRank: $row->race_score_rank !== null ? (int) $row->race_score_rank : null,
            );
        }

        return $grouped;
    }

    private function jsonText(string $column, string $key): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "{$column}->>'{$key}'"
            : "json_extract({$column}, '$.{$key}')";
    }
}
