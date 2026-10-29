<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract as CaptureContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationManifest;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationParser;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;

/** Synthetic races, tokens, approvals and clocks only. Never operational permission or real race evidence. */
final class ObservationRunnerFixture
{
    public static function make(string $storage): array
    {
        $race = [...Pj0315ProbeFixture::target()->race, 'external_race_id' => '28:20250104:01', 'race_type' => 'S級予選'];
        $entries = (new ObservationParser)->parse(Pj0315ProbeFixture::html(), 'text/html; charset=UTF-8', $race)['entries'];
        $input = ApprovalPreflightFixture::make();
        $input['manifest']['target'] = [...$race, 'entries' => $entries];
        $input['source']['parameters'] = Pj0315ProbeFixture::target()->parameters;
        $input['source']['binding_sha256'] = CaptureContract::binding(ObservationContract::URI, $input['source']['parameters']);
        $input['manifest']['request_binding_sha256'] = $input['source']['binding_sha256'];
        $input['manifest']['expires_at'] = '2025-01-05T00:00:00Z';
        $identity = ApprovalPreflightFixture::document($input, 'synthetic-identity', ['kind' => 'TARGET_IDENTITY',
            'target_sha256' => Contract::digest($input['manifest']['target']), 'binding_sha256' => $input['source']['binding_sha256']]);
        $input['manifest']['identity_evidence_sha256'] = $identity;
        $input['source']['policy_sha256'] = ApprovalPreflightFixture::document($input, 'synthetic-policy', ['kind' => 'SOURCE_POLICY',
            'source' => 'PJ0315', 'automatic_access_permitted' => true, 'binding_sha256' => $input['source']['binding_sha256'],
            'expires_at' => '2025-01-05T00:00:00Z']);
        $manifest = ['contract_version' => ObservationContract::VERSION, 'authorization_id' => 'SYNTHETIC-AUTHORIZATION',
            'approval_scope' => 'SYNTHETIC-ONLY', 'revision' => 1, 'approved_from' => '2025-01-03T00:00:00Z',
            'expires_at' => '2025-01-05T00:00:00Z', 'storage_root' => $storage, 'minimum_free_bytes' => 16777216,
            'profile' => 'SYNTHETIC_ACCEPTANCE_ONLY', 'limits' => ObservationManifest::syntheticLimits(), 'targets' => [[
                'race' => $race, 'entries' => $entries, 'request_uri' => ObservationContract::URI,
                'parameters' => $input['source']['parameters'], 'binding_sha256' => $input['source']['binding_sha256'],
                'baseline_snapshot_sha256' => Contract::digest($entries), 'identity_evidence_sha256' => $identity,
                'scheduled_start_at' => '2025-01-04T10:45:00+09:00', 'schedule_source' => 'SYNTHETIC-PC0201', 'slots' => [60, 15]]]];
        $input['decisions'][0]['approved_value'] = [...$input['decisions'][0]['approved_value'],
            'manifest_sha256' => Contract::digest($input['manifest']), 'target_sha256' => Contract::digest($input['manifest']['target']),
            'identity_evidence_sha256' => $identity, 'observation_manifest_sha256' => Contract::digest($manifest),
            'authorization_id' => $manifest['authorization_id'], 'approval_scope' => $manifest['approval_scope']];
        $input['decisions'][2]['approved_value'] = array_intersect_key($input['source'], array_flip(['policy_reference', 'policy_sha256', 'binding_sha256']));
        $input['decisions'][5]['approved_value']['target_sha256'] = Contract::digest($input['manifest']['target']);
        $input['decisions'][6]['approved_value']['observation_storage_sha256'] = Contract::digest([
            'storage_root' => $manifest['storage_root'], 'minimum_free_bytes' => $manifest['minimum_free_bytes']]);
        foreach ($input['decisions'] as &$decision) {
            $decision['approved_at'] = '2025-01-03T00:00:00Z';
            $decision['expires_at'] = '2025-01-05T00:00:00Z';
        }
        unset($decision);
        self::time($input, '2025-01-04T00:45:00Z');
        $input['timing']['observed_scheduled_start_at'] = $manifest['targets'][0]['scheduled_start_at'];
        ApprovalPreflightFixture::sealApprovals($input);

        return [$manifest, $input];
    }

    public static function time(array &$input, string $at, int $slot = 1): void
    {
        $date = new \DateTimeImmutable($at);
        foreach (['request_started_at', 'response_received_at', 'raw_saved_at', 'validation_completed_at', 'publication_completed_at', 'prediction_input_as_of'] as $i => $key) {
            $input['timing'][$key] = $date->modify('+'.$i.' seconds')->format('Y-m-d\TH:i:s.uP');
        }
        $race = $input['manifest']['target'];
        $input['budget']['planned_attempt'] = [...ApprovalPreflightFixture::attempt($race['race_id'], $slot, $at),
            'race_date' => $race['race_date'], 'track_code' => $race['track_code']];
    }
}
