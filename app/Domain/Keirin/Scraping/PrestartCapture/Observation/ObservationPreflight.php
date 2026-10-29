<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Operational\BudgetPreflight;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Preflight;
use DateTimeImmutable;
use Throwable;

final class ObservationPreflight
{
    public function evaluate(ObservationManifest $manifest, int $race, int $slot, array $input, string $scenario, DateTimeImmutable $at): array
    {
        $reasons = [];
        $approval = null;
        try {
            (new SlotPlanner)->requireOpen($manifest, $race, $slot, $input['budget']['planned_attempt']['request_started_at'] ?? '');
            $target = $manifest->target($race);
            $approval = (new Preflight)->evaluate($input, $scenario, $at)->jsonSerialize();
            if ($approval['status'] !== 'OFFLINE_SIMULATION_VALIDATED') {
                $reasons[] = 'D1_D8_SOURCE_CLOCK_NOT_VALIDATED';
            }
            $decisions = array_column($input['decisions'], 'approved_value', 'decision_id');
            $d1 = $decisions['D1'] ?? [];
            if (($d1['observation_manifest_sha256'] ?? null) !== $manifest->digest()
                || ($d1['authorization_id'] ?? null) !== $manifest->data['authorization_id']
                || ($d1['approval_scope'] ?? null) !== $manifest->data['approval_scope']
                || Contract::digest($input['manifest']['target'] ?? []) !== Contract::digest([...$target['race'], 'entries' => $target['entries']])
                || ($input['manifest']['identity_evidence_sha256'] ?? null) !== $target['identity_evidence_sha256']
                || ($input['source']['binding_sha256'] ?? null) !== $target['binding_sha256']
                || Contract::instant($input['timing']['observed_scheduled_start_at'] ?? null) !== Contract::instant($target['scheduled_start_at'])
                || ($input['budget']['planned_attempt']['race_id'] ?? null) !== $race
                || ($input['budget']['planned_attempt']['slot_id'] ?? null) !== $slot) {
                $reasons[] = 'OBSERVATION_SCOPE_OR_SCHEDULE_BINDING_INVALID';
            }
            $expectedLimits = array_intersect_key($manifest->data['limits'], array_flip(BudgetPreflight::LIMIT_KEYS));
            if (Contract::digest($decisions['D2'] ?? []) !== Contract::digest($expectedLimits)
                || ($decisions['D7']['observation_storage_sha256'] ?? null) !== Contract::digest([
                    'storage_root' => $manifest->data['storage_root'], 'minimum_free_bytes' => $manifest->data['minimum_free_bytes']])
                || Contract::instant($at->format('Y-m-d\TH:i:s.uP')) < Contract::instant($manifest->data['approved_from'])
                || Contract::instant($at->format('Y-m-d\TH:i:s.uP')) >= Contract::instant($manifest->data['expires_at'])) {
                $reasons[] = 'OBSERVATION_BUDGET_STORAGE_OR_VALIDITY_NOT_BOUND';
            }
        } catch (Throwable) {
            $reasons[] = 'MANIFEST_SLOT_OR_APPROVAL_INVALID';
        }
        // No real-operation trust verifier exists in this phase. Synthetic proof catalogs never grant real dispatch.
        if ($scenario !== Contract::SIMULATED) {
            $reasons[] = 'INDEPENDENT_OPERATIONAL_APPROVAL_NOT_ESTABLISHED';
        }

        return ['status' => $reasons === [] ? 'OFFLINE_SIMULATION_VALIDATED' : 'NOT_AUTHORIZED',
            'real_send_allowed' => false, 'blocking_reasons' => $reasons, 'approval' => $approval,
            'http_calls' => 0, ...ObservationContract::restrictions()];
    }
}
