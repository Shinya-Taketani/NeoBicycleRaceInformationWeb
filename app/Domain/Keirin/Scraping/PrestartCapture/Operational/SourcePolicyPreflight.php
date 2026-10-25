<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

use App\Domain\Keirin\Scraping\PrestartCapture\CaptureRequest;
use App\Domain\Keirin\Scraping\PrestartCapture\Contract as OfflineContract;
use App\Domain\Keirin\Scraping\PrestartEvidence\SourceValidator;

final class SourcePolicyPreflight
{
    public function evaluate(array $source, ?DecisionRecord $decision, ApprovalEvidence $evidence, string $scenario, int $now): array
    {
        $reasons = [];
        if (array_diff(array_keys($source), ['source', 'request_uri', 'parameters', 'binding_sha256', 'policy_reference', 'policy_sha256']) !== []) {
            $reasons[] = 'SOURCE_BINDING_MISMATCH';
        }
        if (($source['source'] ?? null) !== 'PJ0315' || $decision === null
            || (new ApprovalPreflight)->evaluate([$decision], $evidence, $scenario, $now)['D3']['validation_result'] !== 'SIMULATED_APPROVAL_VALIDATED') {
            $reasons[] = 'SOURCE_NOT_APPROVED';
        }
        $uri = $source['request_uri'] ?? null;
        $parameters = $source['parameters'] ?? null;
        $binding = null;
        if (! is_string($uri) || ! is_array($parameters)) {
            $reasons[] = 'SOURCE_BINDING_MISMATCH';
        } else {
            // These are request-only placeholders, never a claim about an actual HTTP response.
            $route = (new SourceValidator)->validate(['source' => 'PJ0315', 'http_method' => 'POST', 'http_status' => 200,
                'redirect_detected' => false], ['request_uri' => $uri, 'effective_uri' => $uri, 'parameters' => $parameters,
                    'policy' => CaptureRequest::policy()]);
            $binding = OfflineContract::binding($uri, $parameters);
            if ($route['status'] !== 'VERIFIED' || $binding !== ($source['binding_sha256'] ?? null)) {
                $reasons[] = 'SOURCE_BINDING_MISMATCH';
            }
        }
        $approved = $decision?->approvedValue ?? [];
        $reference = $source['policy_reference'] ?? null;
        $sha = $source['policy_sha256'] ?? null;
        $proof = is_string($reference) && is_string($sha) ? $evidence->document($reference, $sha, $scenario) : null;
        if ($proof === null || ($proof['kind'] ?? null) !== 'SOURCE_POLICY' || ($proof['automatic_access_permitted'] ?? null) !== true
            || $reference !== ($approved['policy_reference'] ?? null) || $sha !== ($approved['policy_sha256'] ?? null)) {
            $reasons[] = 'POLICY_UNVERIFIED';
        } else {
            if (($proof['source'] ?? null) !== 'PJ0315' || $binding === null || $binding !== ($proof['binding_sha256'] ?? null)
                || $binding !== ($approved['binding_sha256'] ?? null)) {
                $reasons[] = 'SOURCE_BINDING_MISMATCH';
            }
            $expires = Contract::instant($proof['expires_at'] ?? null);
            if ($expires === null || $now >= $expires) {
                $reasons[] = 'POLICY_EXPIRED';
            }
        }

        return Contract::result($reasons, ['scope' => 'REQUEST_POLICY_ONLY_NO_HTTP_OBSERVATION', 'http_calls' => 0,
            'missing_evidence' => array_intersect($reasons, ['POLICY_UNVERIFIED', 'POLICY_EXPIRED']) === [] ? [] : ['CURRENT_SOURCE_POLICY_PERMISSION']]);
    }
}
