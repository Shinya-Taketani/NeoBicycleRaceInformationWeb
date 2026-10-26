<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use InvalidArgumentException;

final class TargetManifestPreflight
{
    public function evaluate(array $manifest, ?DecisionRecord $decision, ApprovalEvidence $evidence, string $scenario, int $now, ?int $planned): array
    {
        $target = $manifest['target'] ?? [];
        // No evidence resolver, parser, filesystem or network is consulted for the forbidden year.
        if (is_array($target) && is_string($target['race_date'] ?? null) && str_starts_with($target['race_date'], '2026-')) {
            return Contract::result(['TARGET_YEAR_FORBIDDEN']);
        }
        $reasons = [];
        if ($decision === null || (new ApprovalPreflight)->evaluate([$decision], $evidence, $scenario, $now, $planned)['D1']['validation_result'] !== 'SIMULATED_APPROVAL_VALIDATED') {
            $reasons[] = 'TARGET_APPROVAL_NOT_VERIFIED';
        }
        $approved = $decision?->approvedValue ?? [];
        if (($manifest['contract_version'] ?? null) !== Contract::VERSION || ! is_array($target)) {
            return Contract::result(['TARGET_MANIFEST_INVALID']);
        }
        $keys = array_keys($manifest);
        sort($keys);
        if ($keys !== ['approval_reference', 'contract_version', 'expires_at', 'identity_evidence_reference', 'identity_evidence_sha256',
            'previous_manifest_sha256', 'request_binding_sha256', 'revision', 'source', 'target']) {
            $reasons[] = 'TARGET_MANIFEST_FIELDS_INVALID';
        }
        $keys = array_keys($target);
        sort($keys);
        if ($keys !== ['entries', 'external_race_id', 'race_date', 'race_id', 'race_number', 'race_type', 'track_code']) {
            $reasons[] = 'TARGET_FIELDS_INVALID';
        }
        $date = is_string($target['race_date'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $target['race_date']) : false;
        if (! $date || $date->format('Y-m-d') !== $target['race_date'] || $target['race_date'] < '2022-01-01'
            || ! is_int($target['race_id'] ?? null) || $target['race_id'] < 1 || ! is_int($target['race_number'] ?? null)
            || $target['race_number'] < 1 || $target['race_number'] > 12 || ! is_string($target['track_code'] ?? null)
            || preg_match('/^[0-9]{2}$/D', $target['track_code']) !== 1
            || ($target['external_race_id'] ?? null) !== sprintf('%s:%s:%02d', $target['track_code'], str_replace('-', '', $target['race_date']), $target['race_number'])) {
            $reasons[] = 'TARGET_IDENTITY_INVALID';
        }
        if ((new RaceCategoryPolicy)->classify(is_string($target['race_type'] ?? null) ? $target['race_type'] : null) !== RaceCategory::Men) {
            $reasons[] = 'UNSUPPORTED_OR_UNKNOWN_CATEGORY';
        }
        try {
            if (! is_array($target['entries'] ?? null) || ! array_is_list($target['entries'])) {
                throw new InvalidArgumentException('Missing fixed roster.');
            }
            foreach ($target['entries'] as $entry) {
                if (! is_array($entry) || array_diff(array_keys($entry), ['bike_number', 'frame_number', 'external_player_id']) !== []) {
                    throw new InvalidArgumentException('Invalid fixed roster entry.');
                }
            }
            RaceRosterSnapshotDto::validateEntries($target['entries']);
        } catch (InvalidArgumentException) {
            $reasons[] = 'TARGET_ROSTER_INVALID';
        }
        if (($manifest['source'] ?? null) !== 'PJ0315') {
            $reasons[] = 'SOURCE_NOT_APPROVED';
        }
        $revision = $manifest['revision'] ?? null;
        $previous = $manifest['previous_manifest_sha256'] ?? null;
        if (! is_int($revision) || $revision < 1 || ! array_key_exists('previous_manifest_sha256', $manifest)
            || ($revision === 1 ? $previous !== null : ! Contract::sha($previous))
            || $revision !== ($approved['revision'] ?? null) || $previous !== ($approved['previous_manifest_sha256'] ?? null)) {
            $reasons[] = 'MANIFEST_REVISION_MISMATCH';
        }
        if (Contract::digest($manifest) !== ($approved['manifest_sha256'] ?? null)
            || Contract::digest($target) !== ($approved['target_sha256'] ?? null)
            || ($manifest['approval_reference'] ?? null) !== $decision?->approvalReference) {
            $reasons[] = 'TARGET_MANIFEST_APPROVAL_MISMATCH';
        }
        $expires = Contract::instant($manifest['expires_at'] ?? null);
        if ($expires === null || $now >= $expires || $expires > (Contract::instant($decision?->expiresAt) ?? 0)) {
            $reasons[] = 'MANIFEST_EXPIRED_OR_INVALID';
        }
        if ($planned === null) {
            $reasons[] = 'PLANNED_REQUEST_TIME_UNKNOWN';
        } elseif ($expires !== null && $planned >= $expires) {
            $reasons[] = 'MANIFEST_EXPIRED_AT_PLANNED_REQUEST';
        }
        $ref = $manifest['identity_evidence_reference'] ?? null;
        $sha = $manifest['identity_evidence_sha256'] ?? null;
        $proof = is_string($ref) && is_string($sha) ? $evidence->document($ref, $sha, $scenario) : null;
        if ($proof === null || ($proof['kind'] ?? null) !== 'TARGET_IDENTITY'
            || ($proof['target_sha256'] ?? null) !== Contract::digest($target)
            || ! Contract::sha($manifest['request_binding_sha256'] ?? null)
            || ($proof['binding_sha256'] ?? null) !== ($manifest['request_binding_sha256'] ?? null)
            || $ref !== ($approved['identity_evidence_reference'] ?? null) || $sha !== ($approved['identity_evidence_sha256'] ?? null)) {
            $reasons[] = 'TARGET_IDENTITY_EVIDENCE_NOT_VERIFIED';
        }

        return Contract::result($reasons, ['scope' => 'FIXED_SINGLE_PJ0315_RACE', 'external_io_performed' => false,
            'missing_evidence' => in_array('TARGET_IDENTITY_EVIDENCE_NOT_VERIFIED', $reasons, true) ? ['TARGET_IDENTITY_EVIDENCE'] : []]);
    }
}
