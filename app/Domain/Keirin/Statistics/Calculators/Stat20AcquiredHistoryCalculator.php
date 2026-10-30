<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Stat20AcquiredHistoryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat20RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20SourceInputDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus as Status;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus as Quality;
use App\Domain\Keirin\Statistics\Services\AcquiredHistoryFeatureBuildService;
use App\Domain\Keirin\Statistics\Support\AcquiredHistoryLineage;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\Stat20AcquiredHistoryContract as Contract;
use App\Domain\Keirin\Statistics\Support\Stat20AcquiredHistorySourceAudit;
use UnexpectedValueException;

class Stat20AcquiredHistoryCalculator
{
    public function __construct(private readonly Stat20Calculator $legacy, private readonly DeterministicJsonHasher $hasher,
        private readonly Stat20AcquiredHistorySourceAudit $audit) {}

    public function calculate(Stat20AcquiredHistoryInputDto $input): array
    {
        $race = $input->race;
        if (AcquiredHistorySupport::jst($race->raceDate)->format('Y') < '2022'
            || AcquiredHistorySupport::jst($race->raceDate)->format('Y') > '2025'
            || count($race->sources) > Contract::MAX_SOURCE_RESULTS || ! isset($race->sourceRuns['STAT-01'])) {
            throw new UnexpectedValueException('Invalid STAT-20 v2 development target or source bound.');
        }
        $groups = $seen = [];
        foreach ($race->sources as $s) {
            if ($s->id < 1 || isset($seen[$s->id]) || ! isset(Contract::VERSIONS[$s->statCode])
                || $s->calculationVersion !== Contract::VERSIONS[$s->statCode] || $s->raceId !== $race->raceId
                || ($race->sourceRuns[$s->statCode] ?? null) !== $s->runId
                || preg_match('/^[a-f0-9]{64}$/D', $s->inputHash) !== 1 || Status::tryFrom($s->status) === null || Quality::tryFrom($s->qualityStatus) === null) {
                throw new UnexpectedValueException('STAT-20 v2 source identity/version/hash/status mismatch.');
            }
            $key = $s->statCode === 'STAT-41' ? 'race' : $s->raceEntryId;
            if (isset($groups[$s->statCode][$key])) {
                throw new UnexpectedValueException('Duplicate STAT-20 v2 source subject.');
            }
            $groups[$s->statCode][$key] = $s;
            $seen[$s->id] = true;
        }
        foreach ($race->sourceRuns as $code => $id) {
            $run = $input->sourceRunAudits[$code] ?? null;
            if (! isset(Contract::VERSIONS[$code]) || ! is_int($id) || $id < 1 || $run === null || $run['run_id'] !== $id
                || $run['version'] !== Contract::VERSIONS[$code] || ! in_array($run['status'], ['SUCCEEDED', 'PARTIALLY_SUCCEEDED'], true)
                || ($code !== 'STAT-01' && ($run['parameters']['stat01_run_id'] ?? null) !== $race->sourceRuns['STAT-01'])) {
                throw new UnexpectedValueException('STAT-20 v2 run audit or lineage mismatch.');
            }
            if (in_array($code, Contract::NEW_STATS, true) && (($run['parameters']['lineage_contract'] ?? null) !== AcquiredHistoryLineage::CONTRACT
                || ($run['parameters']['build_service_version'] ?? null) !== AcquiredHistoryFeatureBuildService::VERSION)) {
                throw new UnexpectedValueException('STAT-20 v2 saved lineage contract unsupported.');
            }
        }
        // Reuse the frozen v1 identity/participant checks for precisely its original fifteen sources.
        $legacyCodes = array_flip(Stat20Calculator::SOURCE_STAT_CODES);
        $legacy = $this->legacy->calculate(new Stat20RaceInputDto($race->raceId, $race->raceDate, $race->scheduledStartAt,
            array_intersect_key($race->sourceRuns, $legacyCodes),
            array_values(array_filter($race->sources, fn ($s) => isset($legacyCodes[$s->statCode]))), $race->entryIdentities));
        $targets = $groups['STAT-01'];
        foreach (Contract::NEW_STATS as $code) {
            foreach ($groups[$code] ?? [] as $s) {
                $target = $targets[$s->raceEntryId] ?? null;
                if ($target === null || $s->subjectType !== 'RACE_ENTRY' || $s->subjectKey !== 'race_entry:'.$s->raceEntryId
                    || $s->playerId !== $target->playerId || $s->bikeNumber !== $target->bikeNumber || $s->inputAsOf != $target->inputAsOf
                    || ($s->evidence['stat01_run_id'] ?? null) !== $target->runId
                    || ($s->evidence['stat01_input_hash'] ?? null) !== $target->inputHash
                    || ($s->evidence['lineage_contract'] ?? null) !== AcquiredHistoryLineage::CONTRACT
                    || ($s->evidence['data_role'] ?? null) !== 'RETROSPECTIVE_ONLY'
                    || ($s->evidence['prediction_use'] ?? null) !== 'NOT_AUTHORIZED'
                    || ($s->evidence['historical_as_of_available'] ?? null) !== false) {
                    throw new UnexpectedValueException('STAT-20 v2 acquired source identity/lineage/use restriction mismatch.');
                }
            }
        }
        $payloads = array_map(fn ($s) => $s->hashPayload(), $race->sources);
        foreach ($payloads as &$p) {
            if ($p['stat_code'] === 'STAT-41') {
                usort($p['evidence']['participant_snapshot'], fn ($a, $b) => $a['race_entry_id'] <=> $b['race_entry_id']);
            }
        }
        unset($p);
        usort($payloads, fn ($a, $b) => [$a['stat_code'], $a['race_entry_id'] ?? 0, $a['id']] <=> [$b['stat_code'], $b['race_entry_id'] ?? 0, $b['id']]);
        $runs = $race->sourceRuns;
        $runAudits = $input->sourceRunAudits;
        ksort($runs);
        ksort($runAudits);
        $context = $this->hasher->hash(['version' => Contract::VERSION, 'legacy_context' => $legacy[0]->evidence['race_context_hash'],
            'runs' => $runs, 'run_audits' => $runAudits, 'sources' => $payloads]);
        $results = [];
        foreach ($legacy as $old) {
            $target = $old->target;
            $counts = array_fill_keys([...array_column(Status::cases(), 'value'), 'DATA_SOURCE_PENDING'], 0);
            $audits = [];
            $blocked = $old->evidence['blocked_reasons'];
            $fetched = null;
            foreach (Contract::VERSIONS as $code => $version) {
                $s = $groups[$code][$code === 'STAT-41' ? 'race' : $target->raceEntryId] ?? null;
                $run = $runAudits[$code] ?? null;
                $failed = ($run['race_item']['status'] ?? null) === 'FAILED';
                if ($failed || ($s === null && ($run['error_count'] ?? 0) > 0 && ($run['race_item'] ?? null) === null)) {
                    $blocked[] = 'UPSTREAM_FAILURE_OR_UNATTRIBUTED_MISSING:'.$code;
                }
                $runEvidence = ['source_run_audit' => $run ?? 'SOURCE_RUN_NOT_SELECTED'];
                if ($s === null) {
                    $counts['DATA_SOURCE_PENDING']++;
                    $audits[$code] = ['status' => 'DATA_SOURCE_PENDING', 'reason' => isset($runs[$code]) ? 'SOURCE_RESULT_MISSING' : 'SOURCE_RUN_NOT_SELECTED'] + $runEvidence;

                    continue;
                }
                if ($failed) {
                    throw new UnexpectedValueException('FAILED upstream race item has saved results.');
                }
                $counts[$s->status]++;
                $description = $this->audit->describe($s, $target->inputAsOf);
                if ($s->status === 'INVALID_INPUT') {
                    $blocked[] = 'UPSTREAM_INVALID_INPUT:'.$code;
                }
                if ($description['history_inconsistent']) {
                    $blocked[] = 'HISTORY_NOT_STRICTLY_BEFORE_INPUT:'.$code;
                }
                $fetched = $s->sourceFetchedAt === null ? $fetched : ($fetched === null ? $s->sourceFetchedAt : max($fetched, $s->sourceFetchedAt));
                $audits[$code] = array_replace($old->features['STAT_QUALITY'][$code] ?? [], [
                    'source_result_id' => $s->id, 'source_run_id' => $s->runId, 'subject_type' => $s->subjectType,
                    'calculation_version' => $version, 'input_hash' => $s->inputHash, 'status' => $s->status, 'quality_status' => $s->qualityStatus,
                    'source_fetched_at' => Stat20SourceInputDto::timestamp($s->sourceFetchedAt),
                    'fetch_vs_input_as_of' => $this->timing($s->sourceFetchedAt, $target->inputAsOf),
                    'fetch_vs_scheduled_start' => $this->timing($s->sourceFetchedAt, $race->scheduledStartAt),
                    'time_scope' => $code === 'STAT-01' ? 'ENTRY_ROW_NOT_ATTRIBUTE_SPECIFIC' : 'SAVED_SOURCE_OBSERVATION_NOT_HISTORY_EVENT_TIME',
                    'lineage_contract' => $s->evidence['lineage_contract'] ?? 'LEGACY_STAT01_EVIDENCE',
                ], $description, $runEvidence);
            }
            $blocked = array_values(array_unique($blocked));
            $denominator = 20 - $counts['NOT_APPLICABLE'];
            $features = array_replace($old->features, ['STAT_QUALITY' => $audits, 'SOURCE_RESULT_COVERAGE' => [
                'scope' => 'TWENTY_SELECTED_UPSTREAM_RESULT_STATUSES_NOT_CONFIDENCE', 'counts' => $counts,
                'applicable_stat_count' => $denominator, 'valid_ratio' => $denominator > 0 ? $counts['VALID'] / $denominator : null,
                'missing_ratio' => $denominator > 0 ? ($counts['MISSING_INPUT'] + $counts['NO_HISTORY'] + $counts['DATA_SOURCE_PENDING']) / $denominator : null,
                'invalid_ratio' => $denominator > 0 ? $counts['INVALID_INPUT'] / $denominator : null,
            ]]);
            $evidence = array_replace($old->evidence, ['source_run_ids' => $runs, 'race_context_hash' => $context,
                'blocked_reasons' => $blocked, 'source_contract' => Contract::VERSION,
                'source_scope' => 'TWENTY_UPSTREAM_SAVED_RESULTS_NOT_ALL_46_REQUIREMENTS']);
            $results[] = new Stat20FeatureResultDto($target, $blocked !== [] ? Status::InvalidInput : $old->status, Quality::Degraded,
                $fetched, $features, $evidence, $this->hasher->hash(['version' => Contract::VERSION, 'context' => $context, 'entry' => $target->raceEntryId]));
        }

        return $results;
    }

    private function timing(?\DateTimeImmutable $value, ?\DateTimeImmutable $cutoff): string
    {
        return match (true) {
            $value === null || $cutoff === null => 'UNKNOWN', $value < $cutoff => 'BEFORE', $value == $cutoff => 'AT', default => 'AFTER',
        };
    }
}
