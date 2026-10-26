<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

use JsonException;

/** Trust anchors are supplied separately from the target manifest; this phase accepts synthetic evidence only. */
final readonly class ApprovalEvidence
{
    public function __construct(private array $anchors = [], private array $documents = []) {}

    public function document(?string $reference, ?string $sha256, string $scenario): ?array
    {
        if ($scenario !== Contract::SIMULATED || ! Contract::reference($reference) || ! Contract::sha($sha256)
            || ($this->anchors[$reference] ?? null) !== $sha256 || ! is_string($this->documents[$reference] ?? null)) {
            return null;
        }
        $bytes = $this->documents[$reference];
        if (strlen($bytes) > 65536 || ! hash_equals($sha256, hash('sha256', $bytes))) {
            return null;
        }
        try {
            $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($document) && ($document['scope'] ?? null) === Contract::SIMULATED ? $document : null;
    }

    public function verifies(DecisionRecord $record, string $scenario): bool
    {
        $document = $this->document($record->evidenceReference, $record->evidenceSha256, $scenario);

        return $document !== null && ($document['kind'] ?? null) === 'DECISION_APPROVAL'
            && ($document['status'] ?? null) === 'APPROVED'
            && ($document['contract_version'] ?? null) === Contract::VERSION
            && is_array($document['approval_payload'] ?? null)
            && Contract::digest($document['approval_payload']) === Contract::digest($record->approvalPayload());
    }
}
