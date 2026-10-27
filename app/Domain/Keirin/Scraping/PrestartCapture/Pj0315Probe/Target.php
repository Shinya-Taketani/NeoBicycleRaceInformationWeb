<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;

final readonly class Target
{
    public const URI = 'https://keirin.jp/pc/racelive';

    public function __construct(public array $race, public array $entries, public array $parameters, public array $evidence)
    {
        $date = $race['race_date'] ?? null;
        $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        $keys = array_keys($parameters);
        sort($keys);
        if (! $parsed || $parsed->format('Y-m-d') !== $date || ! str_starts_with($date, '2025-')
            || ! is_int($race['race_id'] ?? null) || $race['race_id'] < 1
            || ! is_string($race['track_code'] ?? null) || preg_match('/^[0-9]{2}$/D', $race['track_code']) !== 1
            || ! is_int($race['race_number'] ?? null) || $race['race_number'] < 1 || $race['race_number'] > 12
            || $keys !== ['disp', 'encp'] || $parameters['disp'] !== 'PJ0315'
            || ! is_string($parameters['encp']) || $parameters['encp'] === '' || strlen($parameters['encp']) > 8192) {
            throw new RuntimeException('Invalid or forbidden single 2025 PJ0315 target.');
        }
        RaceRosterSnapshotDto::validateEntries(array_map(fn ($row) => [...$row, 'frame_number' => null], $entries));
    }

    public static function load(string $file, string $sha): self
    {
        if (! str_starts_with($file, '/') || realpath($file) !== $file || is_link($file)
            || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
            throw new RuntimeException('Sealed saved target file required.');
        }
        $bytes = EvidenceFiles::read(dirname($file), basename($file));
        if (strlen($bytes) > 1048576 || ! hash_equals($sha, hash('sha256', $bytes))) {
            throw new RuntimeException('Saved target evidence SHA mismatch.');
        }
        $document = EvidenceFiles::object($bytes);
        if (! is_array($document['cases'] ?? null) || count($document['cases']) !== 1 || ! isset($document['cases'][0])) {
            throw new RuntimeException('Exactly one saved race evidence case required.');
        }
        $case = $document['cases'][0];
        if (! is_array($case) || ! is_array($case['target']['race'] ?? null)
            || ! is_array($case['target']['entries'] ?? null) || ! is_array($case['log'] ?? null)
            || ! is_array($case['request'] ?? null) || ! is_array($case['log']['request_parameters'] ?? null)
            || ! is_string($case['fixture_file'] ?? null) || ! is_int($case['log']['id'] ?? null)
            || ! is_int($case['log']['response_size'] ?? null) || ! is_string($case['log']['sha256'] ?? null)
            || ! is_string($case['log']['content_type'] ?? null)) {
            throw new RuntimeException('Incomplete saved single-race evidence.');
        }
        $race = $case['target']['race'];
        $log = $case['log'];
        $request = $case['request'];
        $target = new self($race, array_map(fn ($entry) => ['bike_number' => $entry['bike_number'],
            'external_player_id' => $entry['external_player_id']], $case['target']['entries']), $log['request_parameters'],
            ['source_file_sha256' => $sha, 'fetch_log_id' => $log['id'], 'baseline_raw_sha256' => $log['sha256']]);
        if (($log['request_method'] ?? null) !== 'POST' || ($log['request_url'] ?? null) !== self::URI
            || ($log['source'] ?? null) !== 'keirin_jp' || ($request['source'] ?? null) !== 'keirin_jp'
            || ($log['race_id'] ?? null) !== $race['race_id'] || ($request['race_id'] ?? null) !== $race['race_id']
            || ($request['race_date'] ?? null) !== $race['race_date'] || ($request['race_number'] ?? null) !== $race['race_number']
            || ($request['external_race_id'] ?? null) !== sprintf('%s:%s:%02d', $race['track_code'], str_replace('-', '', $race['race_date']), $race['race_number'])
            || ($request['encrypted_parameter'] ?? null) !== $target->parameters['encp']
            || ($case['original']['sha256'] ?? null) !== $log['sha256'] || ($case['original']['bytes'] ?? null) !== $log['response_size']) {
            throw new RuntimeException('Saved race/request/fetch-log binding mismatch.');
        }
        $raw = EvidenceFiles::read(dirname($file), $case['fixture_file']);
        if (strlen($raw) !== $log['response_size'] || hash('sha256', $raw) !== $log['sha256']
            || (new Inspector)->inspect($raw, $log['content_type'], $target)['status'] !== 'PARSED_IDENTITY_VERIFIED') {
            throw new RuntimeException('Saved Raw identity or integrity not established.');
        }

        return $target;
    }
}
