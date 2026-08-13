<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\Bt02SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Bt02SourceVerifier
{
    public function __construct(private readonly Bt02SourceManifest $manifest = new Bt02SourceManifest) {}

    /**
     * Verifies database identity and completeness. PG COPY byte fingerprints are
     * fixed in the manifest and intentionally remain a separate preflight contract.
     *
     * @param  list<Bt02SourceManifestEntryDto>  $entries
     */
    public function verify(array $entries): void
    {
        $this->assertCanonicalManifest($entries);
        $ids = array_map(fn (Bt02SourceManifestEntryDto $entry): int => $entry->featureRunId, $entries);
        $runs = DB::table('statistic_feature_runs')->whereIn('id', $ids)->get()->keyBy('id');
        $sourceIds = array_values(array_unique(array_map(fn (Bt02SourceManifestEntryDto $entry): int => $entry->sourceStat01RunId, $entries)));
        $sourceRuns = DB::table('statistic_feature_runs')->whereIn('id', $sourceIds)->get()->keyBy('id');
        $aggregates = collect();
        foreach (array_chunk($ids, 14) as $chunk) {
            $rows = DB::table('statistic_feature_results')
                ->selectRaw('feature_run_id, stat_code, calculation_version, subject_type')
                ->selectRaw('COUNT(*) AS row_count, COUNT(DISTINCT subject_key) AS subject_count')
                ->selectRaw('COUNT(DISTINCT input_hash) AS input_hash_count')
                ->selectRaw("SUM(CASE WHEN input_hash IS NULL OR input_hash = '' THEN 1 ELSE 0 END) AS missing_input_hash_count")
                ->selectRaw('COUNT(DISTINCT race_id) AS race_count')
                ->selectRaw('COUNT(DISTINCT race_entry_id) AS race_entry_count')
                ->whereIn('feature_run_id', $chunk)
                ->groupBy('feature_run_id', 'stat_code', 'calculation_version', 'subject_type')
                ->get();
            $aggregates = $aggregates->concat($rows);
        }
        $aggregates = $aggregates->groupBy('feature_run_id');

        foreach ($entries as $expected) {
            $run = $runs->get($expected->featureRunId);
            $source = $sourceRuns->get($expected->sourceStat01RunId);
            $aggregateRows = $aggregates->get($expected->featureRunId, collect());
            $aggregate = $aggregateRows->count() === 1 ? $aggregateRows->first() : null;
            $parameters = $run !== null ? $this->parameters($run->parameters) : [];
            $actualSubjectCount = (int) ($aggregate->subject_count ?? 0);
            $actualGrainCount = $expected->subjectType === 'RACE'
                ? (int) ($aggregate->race_count ?? 0)
                : (int) ($aggregate->race_entry_count ?? 0);
            $valid = $run !== null
                && $source !== null
                && (string) $run->run_uuid === $expected->featureRunUuid
                && (string) $run->stat_code === $expected->statCode
                && (string) $run->calculation_version === $expected->calculationVersion
                && (string) $run->target_from === $expected->targetFrom
                && (string) $run->target_to === $expected->targetTo
                && ($parameters['history_from'] ?? null) === $expected->historyFrom
                && (int) ($parameters['stat01_run_id'] ?? 0) === $expected->sourceStat01RunId
                && (string) $source->run_uuid === $expected->sourceStat01RunUuid
                && (string) $source->stat_code === 'STAT-01'
                && (string) $source->calculation_version === 'STAT-01-existing-db-v1'
                && (string) $source->target_from === $expected->targetFrom
                && (string) $source->target_to === $expected->targetTo
                && (int) $source->target_race_count === $expected->processedRaceCount
                && (int) $source->processed_race_count === $expected->processedRaceCount
                && (int) $source->target_entry_count === $expected->targetEntryCount
                && (int) $source->error_count === 0
                && in_array((string) $source->status, ['SUCCEEDED', 'PARTIALLY_SUCCEEDED'], true)
                && (int) $run->target_race_count === $expected->processedRaceCount
                && (int) $run->processed_race_count === $expected->processedRaceCount
                && (int) $run->target_entry_count === $expected->targetEntryCount
                && (int) $run->error_count === 0
                && in_array((string) $run->status, ['SUCCEEDED', 'PARTIALLY_SUCCEEDED'], true)
                && (int) ($aggregate->row_count ?? 0) === $expected->rowCount
                && $actualSubjectCount === $expected->rowCount
                && $actualGrainCount === $expected->rowCount
                && (int) ($aggregate->input_hash_count ?? 0) === $expected->rowCount
                && (int) ($aggregate->missing_input_hash_count ?? 0) === 0
                && (string) ($aggregate->stat_code ?? '') === $expected->statCode
                && (string) ($aggregate->calculation_version ?? '') === $expected->calculationVersion
                && (string) ($aggregate->subject_type ?? '') === $expected->subjectType;
            if (! $valid) {
                throw new RuntimeException("Fixed BT-02 source run {$expected->featureRunId} was invalid.");
            }
        }
    }

    /** @param list<Bt02SourceManifestEntryDto> $entries */
    private function assertCanonicalManifest(array $entries): void
    {
        $expected = $this->manifestMap($this->manifest->entries());
        $actual = $this->manifestMap($entries);
        if ($actual !== $expected) {
            throw new RuntimeException('BT-02 sources did not match the fixed 56-entry manifest.');
        }
    }

    /**
     * This comparison intentionally includes verifier-only expected counts while
     * leaving the frozen V1 manifest serialization and hash unchanged.
     *
     * @param  list<Bt02SourceManifestEntryDto>  $entries
     * @return array<string, array<string, int|string|null>>
     */
    private function manifestMap(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            if (! $entry instanceof Bt02SourceManifestEntryDto) {
                throw new RuntimeException('BT-02 source manifest entry type was invalid.');
            }
            $key = $entry->year.'|'.$entry->statCode;
            if (isset($map[$key])) {
                throw new RuntimeException("BT-02 source manifest key {$key} was duplicated.");
            }
            $map[$key] = [
                'year' => $entry->year,
                'stat_code' => $entry->statCode,
                'feature_run_id' => $entry->featureRunId,
                'feature_run_uuid' => $entry->featureRunUuid,
                'calculation_version' => $entry->calculationVersion,
                'source_stat01_run_id' => $entry->sourceStat01RunId,
                'source_stat01_run_uuid' => $entry->sourceStat01RunUuid,
                'target_from' => $entry->targetFrom,
                'target_to' => $entry->targetTo,
                'history_from' => $entry->historyFrom,
                'subject_type' => $entry->subjectType,
                'processed_race_count' => $entry->processedRaceCount,
                'target_entry_count' => $entry->targetEntryCount,
                'row_count' => $entry->rowCount,
                'source_fingerprint_sha256' => $entry->sourceFingerprintSha256,
                'content_fingerprint_sha256' => $entry->contentFingerprintSha256,
            ];
        }
        ksort($map);

        return $map;
    }

    /** @return array<string, mixed> */
    private function parameters(mixed $parameters): array
    {
        if (is_array($parameters)) {
            return $parameters;
        }
        if (! is_string($parameters)) {
            return [];
        }
        $decoded = json_decode($parameters, true);

        return is_array($decoded) ? $decoded : [];
    }
}
