<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence;

final class SourceValidator
{
    /** Rules are explicit synthetic allowlist entries, not permission to contact the real site. */
    public function validate(array $capture, array $private): array
    {
        $reasons = [];
        $unknown = false;
        $policy = $private['policy'];
        $request = $this->uri($private['request_uri']);
        $effective = $this->uri($private['effective_uri']);
        foreach ([$request, $effective] as $uri) {
            if ($uri === null) {
                $unknown = true;
                $reasons[] = 'SOURCE_UNVERIFIED';
            } elseif ($uri['route'] !== $policy['route']) {
                $reasons[] = 'SOURCE_NOT_ALLOWED';
            }
        }
        $expectedMethod = $capture['source'] === 'JSJ017' ? 'GET' : 'POST';
        $selector = $capture['source'] === 'JSJ017' ? 'type' : 'disp';
        $parameters = $private['parameters'];
        $keys = array_keys($parameters);
        sort($keys);
        $expectedKeys = ['encp', $selector];
        sort($expectedKeys);
        if ($policy['method'] !== $expectedMethod || $capture['http_method'] !== $expectedMethod
            || $keys !== $expectedKeys || ($parameters[$selector] ?? null) !== $capture['source']
            || ! is_string($parameters['encp'] ?? null) || $parameters['encp'] === '') {
            $reasons[] = 'SOURCE_PARAMETERS_INVALID';
        }
        // Compare the URI actually sent, not the base URL before GET parameters were appended.
        if ($request !== null && $effective !== null && $request !== $effective) {
            $reasons[] = 'SOURCE_URI_MISMATCH';
        }
        if ($request !== null) {
            $expectedQuery = $expectedMethod === 'GET' ? $parameters : [];
            ksort($expectedQuery);
            if ($request['query'] !== $expectedQuery) {
                $reasons[] = 'SOURCE_PARAMETERS_INVALID';
            }
        }
        if ($capture['redirect_detected'] !== false) {
            $reasons[] = $capture['redirect_detected'] === true ? 'REDIRECT_REFUSED' : 'REDIRECT_UNKNOWN';
            $unknown = $unknown || $capture['redirect_detected'] === null;
        }
        if (is_int($capture['http_status']) && $capture['http_status'] >= 300 && $capture['http_status'] < 400) {
            $reasons[] = 'HTTP_3XX_REJECTED';
            $reasons[] = 'REDIRECT_REFUSED';
        } elseif ($capture['http_status'] !== 200) {
            $reasons[] = 'HTTP_RESPONSE_NOT_OK';
        }
        $reasons = array_values(array_unique($reasons));

        return ['status' => $reasons === [] ? 'VERIFIED' : ($unknown ? 'UNKNOWN' : 'REJECTED'), 'reasons' => $reasons];
    }

    private function uri(?string $uri): ?array
    {
        if ($uri === null || $uri === '' || preg_match('/[\x00-\x20\\\\]/', $uri) === 1) {
            return null;
        }
        $parts = parse_url($uri);
        if (! is_array($parts) || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || ! isset($parts['scheme'], $parts['host'], $parts['path'])
            || ! in_array($parts['scheme'], ['https', 'http'], true)) {
            return null;
        }
        $query = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            foreach (explode('&', $parts['query']) as $pair) {
                $split = explode('=', $pair, 2);
                if (count($split) !== 2 || isset($query[rawurldecode($split[0])])) {
                    return null;
                }
                $query[rawurldecode($split[0])] = rawurldecode($split[1]);
            }
        }
        ksort($query);

        return ['route' => ['scheme' => $parts['scheme'], 'host' => $parts['host'],
            'port' => $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80), 'path' => $parts['path']], 'query' => $query];
    }
}
