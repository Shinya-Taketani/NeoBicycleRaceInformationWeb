<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\Stat09GradeContextDto;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\Stat09GradeContract as Contract;
use UnexpectedValueException;

final class Stat09GradeBreakdownCalculator
{
    public const VERSION = 'STAT-09-acquired-career-grade-breakdown-v3';

    public function __construct(private readonly Stat09Calculator $legacy, private readonly AcquiredHistorySupport $history,
        private readonly DeterministicJsonHasher $hasher) {}

    /** @param array<int,Stat09GradeContextDto> $grades */
    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, array $stages, array $grades): Batch02FeatureResultDto
    {
        $context = $this->history->prepare($target, $histories, $options);
        $base = $this->legacy->calculate($target, $histories, $options, $stages);
        $groups = ['entry_grade' => [], 'meeting_grade' => []];
        $coverage = array_fill_keys(array_keys($groups), ['known_count' => 0, 'unknown_count' => 0, 'unknown_reason_counts' => []]);
        $references = $hashReferences = [];
        $maxFetched = null;
        foreach ($context['events'] as $event) {
            $grade = $grades[$event->raceEntryId] ?? null;
            if (! $grade instanceof Stat09GradeContextDto || $grade->raceId !== $event->raceId || $grade->raceEntryId !== $event->raceEntryId
                || $grade->playerId !== $event->playerId || ($grade->sources['entry_id'] ?? null) !== $event->raceEntryId
                || ($grade->sources['race_id'] ?? null) !== $event->raceId || ($grade->sources['player_id'] ?? null) !== $event->playerId
                || ($grade->sources['projection_contract'] ?? null) !== Contract::PROJECTION
                || ($grade->sources['normalization_contract'] ?? null) !== Contract::NORMALIZATION
                || ! in_array($grade->entryGrade, [...Contract::ENTRY_GRADES, 'UNKNOWN'], true)
                || ! in_array($grade->meetingGrade, ['GP', 'G1', 'G2', 'G3', 'F1', 'F2', 'UNKNOWN'], true)) {
                throw new UnexpectedValueException('STAT09 grade context identity/contract mismatch.');
            }
            foreach (['entry_grade' => $grade->entryGrade, 'meeting_grade' => $grade->meetingGrade] as $axis => $value) {
                $groups[$axis][$value][] = $event;
                $coverage[$axis][$value === 'UNKNOWN' ? 'unknown_count' : 'known_count']++;
                if ($value === 'UNKNOWN') {
                    if (($grade->reasons[$axis] ?? []) === []) {
                        throw new UnexpectedValueException('UNKNOWN grade requires a recorded reason.');
                    }
                    foreach (array_unique($grade->reasons[$axis]) as $reason) {
                        $coverage[$axis]['unknown_reason_counts'][$reason] = ($coverage[$axis]['unknown_reason_counts'][$reason] ?? 0) + 1;
                    }
                }
            }
            $references[] = get_object_vars($grade);
            $hashReferences[] = $grade->hashPayload();
            $maxFetched = max($maxFetched, $event->raceEntryFetchedAt, $event->raceResultFetchedAt, $grade->maxFetchedAt());
        }
        $features = $base->features;
        foreach (['entry_grade' => 'ENTRY_GRADE_BREAKDOWN', 'meeting_grade' => 'MEETING_GRADE_BREAKDOWN'] as $axis => $field) {
            ksort($groups[$axis]);
            $features[$field] = [];
            foreach ($groups[$axis] as $value => $events) {
                // Delegate the exact existing denominators, including abnormal and tied results.
                $features[$field][$value] = $this->legacy->calculate($target, $events, $options, $stages)->features['ACQUIRED_PERIOD'];
            }
            ksort($coverage[$axis]['unknown_reason_counts']);
        }
        $evidence = array_replace($base->evidence, ['stat09_profile' => Contract::PROFILE, 'grade_projection_contract' => Contract::PROJECTION,
            'grade_breakdown' => ['normalization_contract' => Contract::NORMALIZATION, 'references' => $references,
                'coverage' => $coverage, 'reason_count_basis' => 'MULTIPLE_REASONS_PER_UNKNOWN_REFERENCE_NOT_DISTINCT_HISTORY_COUNT',
                'original_raw_and_publication_independently_verified' => false],
            'source_max_fetched_at' => $maxFetched === null ? null : AcquiredHistorySupport::jst($maxFetched)->format('Y-m-d\TH:i:s.uP'),
            'source_max_fetched_at_scope' => 'HISTORY_ENTRY_RESULT_AND_USED_GRADE_CONTEXT_ROW_FETCH_TIMES']);

        return new Batch02FeatureResultDto($target, $base->status, $base->qualityStatus, $features, $evidence,
            $this->hasher->hash(['version' => self::VERSION, 'legacy_input_hash' => $base->inputHash,
                'projection_contract' => Contract::PROJECTION, 'normalization_contract' => Contract::NORMALIZATION,
                'entry_grade_allowlist' => Contract::ENTRY_GRADES, 'meeting_grade_map' => Classification::MAP, 'used_grade_contexts' => $hashReferences]));
    }
}
