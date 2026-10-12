<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Stat20FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat20RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20SourceInputDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use UnexpectedValueException;

class Stat20Calculator
{
    public const STAT_CODE = 'STAT-20';

    public const CALCULATION_VERSION = 'STAT-20-existing-db-v1';

    public const SOURCE_STAT_CODES = ['STAT-01', 'STAT-07', 'STAT-08', 'STAT-10', 'STAT-11', 'STAT-12',
        'STAT-23', 'STAT-24', 'STAT-26', 'STAT-31', 'STAT-32', 'STAT-33', 'STAT-39', 'STAT-41', 'STAT-42'];

    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    /** @return list<Stat20FeatureResultDto> */
    public function calculate(Stat20RaceInputDto $race): array
    {
        if ($race->raceId < 1 || $race->raceDate->format('Y') >= '2026') {
            throw new UnexpectedValueException('Invalid or forbidden STAT-20 target.');
        }
        foreach ($race->sourceRuns as $code => $id) {
            if (! in_array($code, self::SOURCE_STAT_CODES, true) || ! is_int($id) || $id < 1) {
                throw new UnexpectedValueException('Invalid STAT-20 source run selection.');
            }
        }
        $groups = [];
        $ids = [];
        foreach ($race->sources as $source) {
            if ($source->id < 1 || isset($ids[$source->id]) || $source->raceId !== $race->raceId
                || ! in_array($source->statCode, self::SOURCE_STAT_CODES, true)
                || ($race->sourceRuns[$source->statCode] ?? null) !== $source->runId
                || $source->calculationVersion !== $source->statCode.'-existing-db-v1'
                || preg_match('/^[a-f0-9]{64}$/D', $source->inputHash) !== 1
                || StatisticFeatureResultStatus::tryFrom($source->status) === null
                || StatisticQualityStatus::tryFrom($source->qualityStatus) === null) {
                throw new UnexpectedValueException('STAT-20 source identity/version/hash/status mismatch.');
            }
            $ids[$source->id] = true;
            $key = $source->statCode === 'STAT-41' ? 'race' : $source->raceEntryId;
            if (isset($groups[$source->statCode][$key])) {
                throw new UnexpectedValueException('Duplicate STAT-20 source subject.');
            }
            $groups[$source->statCode][$key] = $source;
        }
        $targets = $groups['STAT-01'] ?? [];
        if ($targets === [] || count($targets) > 9) {
            throw new UnexpectedValueException('STAT-20 requires a bounded STAT-01 snapshot.');
        }
        ksort($targets, SORT_NUMERIC);
        $bikes = $players = [];
        $first = reset($targets);
        foreach ($targets as $target) {
            $this->entryIdentity($target, $race);
            if (isset($bikes[$target->bikeNumber])
                || ($target->playerId !== null && isset($players[$target->playerId]))
                || $target->inputAsOf != $first->inputAsOf) {
                throw new UnexpectedValueException('STAT-20 snapshot duplicate identity or input_as_of mismatch.');
            }
            $bikes[$target->bikeNumber] = true;
            if ($target->playerId !== null) {
                $players[$target->playerId] = true;
            }
        }
        foreach ($groups as $code => $subjects) {
            foreach ($subjects as $source) {
                if ($code === 'STAT-41') {
                    $this->raceSource($source, $targets, $race);
                } elseif (! isset($targets[$source->raceEntryId])) {
                    throw new UnexpectedValueException('STAT-20 source is outside the target snapshot.');
                } else {
                    $target = $targets[$source->raceEntryId];
                    $this->entryIdentity($source, $race);
                    if ($source->playerId !== $target->playerId || $source->bikeNumber !== $target->bikeNumber
                        || $source->inputAsOf != $target->inputAsOf
                        || ($code !== 'STAT-01' && (($source->evidence['stat01_run_id'] ?? null) !== $target->runId
                            || ($source->evidence['stat01_input_hash'] ?? null) !== $target->inputHash))) {
                        throw new UnexpectedValueException('STAT-20 target/source snapshot lineage mismatch.');
                    }
                }
            }
        }
        $ordered = $race->sources;
        usort($ordered, fn ($a, $b) => [$a->statCode, $a->raceEntryId ?? 0, $a->id] <=> [$b->statCode, $b->raceEntryId ?? 0, $b->id]);
        $runs = $race->sourceRuns;
        ksort($runs);
        $contextHash = $this->hasher->hash([
            'race_id' => $race->raceId, 'race_date' => $race->raceDate->format('Y-m-d'),
            'scheduled_start_at' => Stat20SourceInputDto::timestamp($race->scheduledStartAt),
            'source_runs' => $runs, 'sources' => array_map($this->sourceHashPayload(...), $ordered),
            'entry_identities' => $race->entryIdentities,
        ]);
        $declaredCounts = array_map(fn ($s) => $s->features['ENTRANT_COUNT'] ?? null, $targets);
        $expected = array_unique($declaredCounts, SORT_REGULAR);
        $expectedCount = count($expected) === 1 && count(array_filter($declaredCounts, is_int(...))) === count($targets)
            ? reset($expected) : null;
        $results = [];
        foreach ($targets as $target) {
            $counts = array_fill_keys([...array_column(StatisticFeatureResultStatus::cases(), 'value'), 'DATA_SOURCE_PENDING'], 0);
            $audits = [];
            $blocked = [];
            $sourceFetchedAt = null;
            foreach (self::SOURCE_STAT_CODES as $code) {
                $source = $groups[$code][$code === 'STAT-41' ? 'race' : $target->raceEntryId] ?? null;
                if ($source === null) {
                    $counts['DATA_SOURCE_PENDING']++;
                    $audits[$code] = ['status' => 'DATA_SOURCE_PENDING', 'reason' => isset($runs[$code]) ? 'SOURCE_RESULT_MISSING' : 'SOURCE_RUN_NOT_SELECTED'];

                    continue;
                }
                $counts[$source->status]++;
                if ($source->status === 'INVALID_INPUT') {
                    $blocked[] = 'UPSTREAM_INVALID_INPUT:'.$code;
                }
                $historyMax = $source->evidence['history_max_at'] ?? null;
                if ($historyMax !== null && ($target->inputAsOf === null || $this->date($historyMax) >= $target->inputAsOf)) {
                    $blocked[] = 'HISTORY_NOT_STRICTLY_BEFORE_INPUT:'.$code;
                }
                if ($source->sourceFetchedAt !== null && ($sourceFetchedAt === null || $source->sourceFetchedAt > $sourceFetchedAt)) {
                    $sourceFetchedAt = $source->sourceFetchedAt;
                }
                $audits[$code] = [
                    'source_result_id' => $source->id, 'source_run_id' => $source->runId,
                    'subject_type' => $source->subjectType, 'calculation_version' => $source->calculationVersion,
                    'input_hash' => $source->inputHash, 'status' => $source->status, 'quality_status' => $source->qualityStatus,
                    'source_fetched_at' => Stat20SourceInputDto::timestamp($source->sourceFetchedAt),
                    'fetch_vs_input_as_of' => $this->timing($source->sourceFetchedAt, $target->inputAsOf),
                    'fetch_vs_scheduled_start' => $this->timing($source->sourceFetchedAt, $race->scheduledStartAt),
                    'time_scope' => $code === 'STAT-01' ? 'ENTRY_ROW_NOT_ATTRIBUTE_SPECIFIC' : 'BACKFILLED_SOURCE_OBSERVATION',
                    'history_result_mode' => $source->evidence['history_result_mode'] ?? null,
                    'history_window_audit' => $this->windows($source->features),
                    'quality_reasons' => $source->evidence['quality_reasons'] ?? [],
                    'unavailable_components' => $source->evidence['unavailable_components'] ?? [],
                    'original_fetch_log_raw_linkage' => 'DATA_SOURCE_PENDING',
                    'official_publication_as_of' => 'NOT_VERIFIED',
                ];
            }
            if ($expectedCount === null || $expectedCount < 5 || $expectedCount > 9 || $expectedCount !== count($targets)) {
                $blocked[] = 'ENTRY_COUNT_MISMATCH_OR_INVALID';
            }
            $status = $blocked !== [] ? StatisticFeatureResultStatus::InvalidInput
                : ($target->playerId === null || $target->inputAsOf === null
                    ? StatisticFeatureResultStatus::MissingInput : StatisticFeatureResultStatus::Partial);
            $denominator = count(self::SOURCE_STAT_CODES) - $counts['NOT_APPLICABLE'];
            $features = [
                'REQUIRED_INPUT_AUDIT' => [
                    'entry_identity' => 'VERIFIED_AGAINST_CURRENT_ROW_NOT_HISTORICAL_PROOF',
                    'player_id' => $target->playerId === null ? 'MISSING_INPUT' : 'RESOLVED',
                    'input_as_of' => $target->inputAsOf === null ? 'UNKNOWN' : 'AVAILABLE',
                    'scheduled_start_at' => $race->scheduledStartAt === null ? 'UNKNOWN' : 'AVAILABLE',
                    'snapshot_entry_count' => $expectedCount === count($targets) && $expectedCount >= 5 && $expectedCount <= 9
                        ? 'CONSISTENT' : 'INVALID_INPUT',
                ],
                'SOURCE_RESULT_COVERAGE' => [
                    'scope' => 'CATEGORY1_UPSTREAM_RESULT_STATUSES_NOT_CONFIDENCE',
                    'counts' => $counts, 'applicable_stat_count' => $denominator,
                    'valid_ratio' => $denominator > 0 ? (float) $counts['VALID'] / $denominator : null,
                    'missing_ratio' => $denominator > 0 ? (float) ($counts['MISSING_INPUT'] + $counts['NO_HISTORY'] + $counts['DATA_SOURCE_PENDING']) / $denominator : null,
                    'invalid_ratio' => $denominator > 0 ? (float) $counts['INVALID_INPUT'] / $denominator : null,
                ],
                'RACE_ENTRY_COVERAGE' => [
                    'expected_entry_count' => $expectedCount, 'snapshot_entry_count' => count($targets),
                    'resolved_player_count' => count($players),
                    'resolved_player_ratio' => count($players) / count($targets),
                ],
                'STAT_QUALITY' => $audits,
                'CONFIDENCE' => null,
            ];
            $evidence = [
                'stat01_run_id' => $target->runId, 'stat01_input_hash' => $target->inputHash,
                'source_run_ids' => $runs, 'race_context_hash' => $contextHash,
                'blocked_reasons' => $blocked,
                'source_scope' => 'SAVED_FEATURE_RESULTS_ONLY',
                'snapshot_verification' => 'IDENTITY_LINEAGE_AND_HASH_FORMAT_NOT_ORIGINAL_SOURCE_SEAL',
                'unverified_components' => ['ORIGINAL_RAW_AND_FETCH_LOG_LINKAGE', 'OFFICIAL_PUBLICATION_TIME',
                    'ORIGINAL_SOURCE_HASH_RECONSTRUCTION', 'ORIGINAL_PARSER_VERSION',
                    'SOURCE_TRUST_WEIGHT', 'CONFIDENCE_POLICY', 'MODEL_VALIDATION'],
                'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED',
            ];
            $results[] = new Stat20FeatureResultDto($target, $status, StatisticQualityStatus::Degraded, $sourceFetchedAt,
                $features, $evidence, $this->hasher->hash(['stat_code' => self::STAT_CODE,
                    'calculation_version' => self::CALCULATION_VERSION, 'race_context_hash' => $contextHash,
                    'race_entry_id' => $target->raceEntryId]));
        }

        return $results;
    }

    private function entryIdentity(Stat20SourceInputDto $source, Stat20RaceInputDto $race): void
    {
        $identity = $race->entryIdentities[$source->raceEntryId] ?? null;
        if ($source->subjectType !== 'RACE_ENTRY' || $source->raceEntryId === null || $source->raceEntryId < 1
            || $source->subjectKey !== 'race_entry:'.$source->raceEntryId
            || $source->bikeNumber === null || $source->bikeNumber < 1 || $source->bikeNumber > 9
            || ($source->playerId !== null && $source->playerId < 1)
            || ! is_array($identity) || ! array_key_exists('player_id', $identity)
            || ($identity['race_id'] ?? null) !== $race->raceId
            || $identity['player_id'] !== $source->playerId
            || ($identity['bike_number'] ?? null) !== $source->bikeNumber) {
            throw new UnexpectedValueException('STAT-20 race/entry/player/bike identity mismatch.');
        }
    }

    private function raceSource(Stat20SourceInputDto $source, array $targets, Stat20RaceInputDto $race): void
    {
        if ($source->subjectType !== 'RACE' || $source->subjectKey !== 'race:'.$race->raceId
            || $source->raceEntryId !== null || $source->playerId !== null || $source->bikeNumber !== null
            || ($source->evidence['stat01_run_id'] ?? null) !== reset($targets)->runId
            || $source->inputAsOf != reset($targets)->inputAsOf) {
            throw new UnexpectedValueException('STAT-20 race-grain source lineage mismatch.');
        }
        $participants = $source->evidence['participant_snapshot'] ?? [];
        if (! is_array($participants) || count($participants) !== count($targets)) {
            throw new UnexpectedValueException('STAT-20 race-grain participant snapshot incomplete.');
        }
        $seen = [];
        foreach ($participants as $participant) {
            if (! is_array($participant) || ! is_int($participant['race_entry_id'] ?? null)) {
                throw new UnexpectedValueException('STAT-20 race-grain participant identity invalid.');
            }
            $id = $participant['race_entry_id'] ?? null;
            $target = $targets[$id] ?? null;
            if (isset($seen[$id]) || $target === null || ($participant['player_id'] ?? null) !== $target->playerId
                || ($participant['bike_number'] ?? null) !== $target->bikeNumber
                || ($participant['stat01_input_hash'] ?? null) !== $target->inputHash) {
                throw new UnexpectedValueException('STAT-20 race-grain participant identity mismatch.');
            }
            $seen[$id] = true;
        }
    }

    private function sourceHashPayload(Stat20SourceInputDto $source): array
    {
        $payload = $source->hashPayload();
        if ($source->statCode === 'STAT-41') {
            // Participant identity is a set; retain the original saved evidence unchanged.
            usort($payload['evidence']['participant_snapshot'], fn ($a, $b) => $a['race_entry_id'] <=> $b['race_entry_id']);
        }

        return $payload;
    }

    private function timing(?DateTimeImmutable $fetched, ?DateTimeImmutable $reference): string
    {
        return match (true) {
            $fetched === null, $reference === null => 'UNKNOWN',
            $fetched < $reference => 'BEFORE',
            $fetched == $reference => 'AT',
            default => 'AFTER',
        };
    }

    private function date(mixed $text): DateTimeImmutable
    {
        if (! is_string($text) || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $text) !== 1) {
            throw new UnexpectedValueException('Invalid stored history time.');
        }

        $date = new DateTimeImmutable($text);
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) {
            throw new UnexpectedValueException('Invalid stored history time.');
        }

        return $date;
    }

    private function windows(array $node, string $path = ''): array
    {
        $audit = [];
        if (array_key_exists('window_complete', $node)) {
            $audit[$path] = array_intersect_key($node, array_flip(['window_complete', 'sample_count',
                'history_start_at', 'history_end_at', 'window_start_at']));
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $audit += $this->windows($value, $path === '' ? (string) $key : $path.'.'.$key);
            }
        }
        ksort($audit);

        return $audit;
    }
}
