<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

use InvalidArgumentException;

final class ApprovalPreflight
{
    public const NEXT = ['D1' => 'DECIDE_FIXED_TARGETS_AND_IDENTITY_EVIDENCE', 'D2' => 'DECIDE_SLOTS_BUDGET_AND_STOP_POLICY',
        'D3' => 'VERIFY_SOURCE_PERMISSION_AND_BINDING', 'D4' => 'DECIDE_PJ_ONLY_AND_SECOND_SOURCE_UNAVAILABLE',
        'D5' => 'DECIDE_CHANGE_CANCELLATION_AND_POSTPONEMENT_POLICY', 'D6' => 'VERIFY_CLOCK_BOUNDS_AND_TIMEZONE',
        'D7' => 'DECIDE_PRIVATE_STORAGE_RETENTION_CAPACITY_AND_RECOVERY', 'D8' => 'KEEP_PREDICTION_DISCONNECTED_AND_DEFINE_SEPARATE_GATE'];

    /** @param list<DecisionRecord> $records */
    public function evaluate(array $records, ApprovalEvidence $evidence, string $scenario, int $now, ?int $planned): array
    {
        Contract::scenario($scenario);
        $byId = [];
        foreach ($records as $record) {
            if (! $record instanceof DecisionRecord || isset($byId[$record->decisionId])) {
                throw new InvalidArgumentException('Duplicate or invalid decision record.');
            }
            $byId[$record->decisionId] = $record;
        }
        $results = [];
        foreach (Contract::DECISIONS as $id) {
            $record = $byId[$id] ?? null;
            $reasons = $missing = [];
            if ($record === null) {
                $reasons[] = 'DECISION_MISSING';
                $missing[] = 'INDEPENDENT_APPROVAL_EVIDENCE';
            } else {
                if ($record->contractVersion !== Contract::VERSION) {
                    $reasons[] = 'CONTRACT_VERSION_MISMATCH';
                }
                if ($record->status !== DecisionStatus::Approved) {
                    $reasons[] = $record->status->value;
                }
                if ($record->approvedValue === null || $record->approvedValue === []) {
                    $reasons[] = 'APPROVED_VALUE_MISSING';
                } elseif (! $this->scopedValue($record)) {
                    $reasons[] = 'APPROVED_VALUE_OUTSIDE_PREFLIGHT_SCOPE';
                }
                foreach (['approvalReference', 'evidenceReference', 'approvedBy'] as $field) {
                    if (! Contract::reference($record->{$field})) {
                        $missing[] = $field;
                    }
                }
                if (! $record->evidenceVerified || ! $evidence->verifies($record, $scenario)) {
                    $reasons[] = 'INDEPENDENT_APPROVAL_NOT_VERIFIED';
                    $missing[] = 'INDEPENDENT_APPROVAL_EVIDENCE';
                }
                $approved = Contract::instant($record->approvedAt);
                $expires = Contract::instant($record->expiresAt);
                if ($approved === null || $expires === null || $approved >= $expires) {
                    $reasons[] = 'APPROVAL_TIME_INVALID';
                } else {
                    if ($approved > $now) {
                        $reasons[] = 'APPROVAL_TIME_INVALID';
                    } elseif ($now >= $expires) {
                        $reasons[] = 'EXPIRED';
                    }
                    if ($planned === null) {
                        $reasons[] = 'PLANNED_REQUEST_TIME_UNKNOWN';
                    } elseif ($planned < $approved) {
                        $reasons[] = 'APPROVAL_NOT_YET_VALID_AT_PLANNED_REQUEST';
                    } elseif ($planned >= $expires) {
                        $reasons[] = 'APPROVAL_EXPIRED_AT_PLANNED_REQUEST';
                    }
                }
                if ($missing !== []) {
                    $reasons[] = 'APPROVAL_METADATA_MISSING';
                }
            }
            $results[$id] = ['decision_id' => $id, 'input_status' => $record?->status->value ?? 'MISSING',
                'validation_result' => $reasons === [] ? 'SIMULATED_APPROVAL_VALIDATED' : 'NOT_AUTHORIZED',
                'failure_reasons' => array_values(array_unique($reasons)), 'missing_evidence' => array_values(array_unique($missing)),
                'required_next_decision' => $reasons === [] ? null : self::NEXT[$id],
                'source_contract_version' => $record?->contractVersion];
        }

        return $results;
    }

    private function scopedValue(DecisionRecord $record): bool
    {
        $value = $record->approvedValue;

        return match ($record->decisionId) {
            'D4' => ($value['primary_source'] ?? null) === 'PJ0315'
                && ($value['second_source_status'] ?? null) === 'SECOND_SOURCE_NOT_CAPTURED'
                && ($value['jsj017_authorized'] ?? null) === false,
            'D5' => ($value['change_policy'] ?? null) === 'PRESERVE_UNKNOWN_APPEND_ONLY_NO_EXTRA_FETCH',
            'D6' => ($value['clock_policy'] ?? null) === 'SIMULATED_CLOCK_WITH_BOUNDS'
                && Contract::sha($value['target_sha256'] ?? null),
            'D7' => Contract::reference($value['storage_reference'] ?? null)
                && Contract::reference($value['recovery_reference'] ?? null) && ($value['retention'] ?? null) === 'NO_AUTOMATIC_DELETION',
            'D8' => ($value['prediction_use'] ?? null) === 'NOT_AUTHORIZED',
            default => true,
        };
    }
}
