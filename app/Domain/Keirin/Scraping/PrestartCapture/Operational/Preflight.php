<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Preflight
{
    public function evaluate(array $input, string $scenario, DateTimeImmutable $at): PreflightReport
    {
        Contract::scenario($scenario);
        if (array_diff(array_keys($input), ['decisions', 'manifest', 'budget', 'source', 'timing', 'simulation_evidence']) !== []) {
            throw new InvalidArgumentException('Unknown offline preflight input fields.');
        }
        foreach ($input as $value) {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Offline preflight sections must be objects or record lists.');
            }
        }
        $rows = $input['decisions'] ?? [];
        if (! array_is_list($rows) || count($rows) > 8) {
            throw new InvalidArgumentException('At most eight unique decisions are accepted.');
        }
        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Invalid decision record.');
            }
            $record = DecisionRecord::fromArray($row);
            if (isset($records[$record->decisionId])) {
                throw new InvalidArgumentException('Duplicate decision ID.');
            }
            $records[$record->decisionId] = $record;
        }
        $proofs = $input['simulation_evidence'] ?? [];
        if (! is_array($proofs['anchors'] ?? []) || ! is_array($proofs['documents'] ?? [])) {
            throw new InvalidArgumentException('Invalid synthetic evidence catalog.');
        }
        // Input-supplied anchors can exercise synthetic verification, never establish operational trust.
        $evidence = $scenario === Contract::SIMULATED
            ? new ApprovalEvidence($proofs['anchors'] ?? [], $proofs['documents'] ?? []) : new ApprovalEvidence;
        $now = Contract::instant($at->format('Y-m-d\TH:i:s.uP'));
        $planned = Contract::instant($input['budget']['planned_attempt']['request_started_at'] ?? null);
        $decisions = (new ApprovalPreflight)->evaluate(array_values($records), $evidence, $scenario, $now, $planned);
        $manifest = $input['manifest'] ?? [];
        $target = is_array($manifest['target'] ?? null) ? $manifest['target'] : [];
        $reasons = [];
        if (($manifest['request_binding_sha256'] ?? null) !== ($input['source']['binding_sha256'] ?? null)
            || ! Contract::sha($manifest['request_binding_sha256'] ?? null)) {
            $reasons[] = 'TARGET_SOURCE_BINDING_MISMATCH';
        }
        $expiry = Contract::instant($manifest['expires_at'] ?? null);
        if ($planned === null || $expiry === null || $planned >= $expiry || $planned < $now) {
            $reasons[] = 'PLANNED_ATTEMPT_OUTSIDE_VALIDITY';
        }
        $timing = $input['timing'] ?? [];
        $schedule = Contract::instant($timing['observed_scheduled_start_at'] ?? null);
        if (($records['D6']->approvedValue['target_sha256'] ?? null) !== Contract::digest($target)
            || $schedule === null || (new DateTimeImmutable($timing['observed_scheduled_start_at']))
                ->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d') !== ($target['race_date'] ?? null)) {
            $reasons[] = 'TIMING_TARGET_BINDING_NOT_ESTABLISHED';
        }
        if ($planned === null || $planned !== Contract::instant($timing['request_started_at'] ?? null)) {
            $reasons[] = 'PLANNED_REQUEST_CLOCK_MISMATCH';
        }
        if ($scenario !== Contract::SIMULATED && $proofs !== []) {
            $reasons[] = 'SIMULATED_EVIDENCE_NOT_OPERATIONAL';
        }

        return new PreflightReport($scenario, $decisions,
            (new TargetManifestPreflight)->evaluate($manifest, $records['D1'] ?? null, $evidence, $scenario, $now, $planned),
            (new BudgetPreflight)->evaluate($input['budget'] ?? [], $records['D2']->approvedValue ?? [], $target),
            (new SourcePolicyPreflight)->evaluate($input['source'] ?? [], $records['D3'] ?? null, $evidence, $scenario, $now, $planned),
            (new TimingPreflight)->evaluate($timing, $scenario), $reasons);
    }
}
