<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

use InvalidArgumentException;

final readonly class DecisionRecord
{
    public function __construct(
        public string $decisionId,
        public string $contractVersion,
        public DecisionStatus $status,
        public array $proposalValue,
        public ?array $approvedValue = null,
        public ?string $approvalReference = null,
        public ?string $evidenceReference = null,
        public ?string $evidenceSha256 = null,
        public bool $evidenceVerified = false,
        public ?string $approvedBy = null,
        public ?string $approvedAt = null,
        public ?string $expiresAt = null,
        public ?string $unresolvedReason = null,
    ) {
        if (! in_array($decisionId, Contract::DECISIONS, true)) {
            throw new InvalidArgumentException('Unknown decision ID.');
        }
    }

    public static function fromArray(array $data): self
    {
        $allowed = ['decision_id', 'contract_version', 'status', 'proposal_value', 'approved_value', 'approval_reference',
            'evidence_reference', 'evidence_sha256', 'evidence_verified', 'approved_by', 'approved_at', 'expires_at', 'unresolved_reason'];
        foreach (['decision_id', 'contract_version', 'status'] as $field) {
            if (! is_string($data[$field] ?? null)) {
                throw new InvalidArgumentException('Missing typed decision field.');
            }
        }
        foreach (array_diff($allowed, ['decision_id', 'contract_version', 'status', 'proposal_value', 'approved_value', 'evidence_verified']) as $field) {
            if (isset($data[$field]) && ! is_string($data[$field])) {
                throw new InvalidArgumentException('Invalid optional decision field type.');
            }
        }
        $status = DecisionStatus::tryFrom($data['status']);
        if (array_diff(array_keys($data), $allowed) !== [] || $status === null || ! is_array($data['proposal_value'] ?? null)
            || (isset($data['approved_value']) && ! is_array($data['approved_value']))
            || (array_key_exists('evidence_verified', $data) && ! is_bool($data['evidence_verified']))) {
            throw new InvalidArgumentException('Invalid decision shape/status.');
        }

        return new self($data['decision_id'], $data['contract_version'], $status, $data['proposal_value'],
            $data['approved_value'] ?? null, $data['approval_reference'] ?? null, $data['evidence_reference'] ?? null,
            $data['evidence_sha256'] ?? null, $data['evidence_verified'] ?? false, $data['approved_by'] ?? null,
            $data['approved_at'] ?? null, $data['expires_at'] ?? null, $data['unresolved_reason'] ?? null);
    }

    public function approvalPayload(): array
    {
        return ['decision_id' => $this->decisionId, 'contract_version' => $this->contractVersion,
            'approved_value' => $this->approvedValue, 'approval_reference' => $this->approvalReference,
            'evidence_reference' => $this->evidenceReference, 'approved_by' => $this->approvedBy,
            'approved_at' => $this->approvedAt, 'expires_at' => $this->expiresAt];
    }
}
