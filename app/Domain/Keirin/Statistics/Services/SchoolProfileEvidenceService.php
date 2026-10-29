<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\Support\SchoolProfileParser;
use DateTimeImmutable;
use RuntimeException;

final class SchoolProfileEvidenceService
{
    public function __construct(private readonly SchoolProfileParser $parser, private readonly CharacterEncodingConverter $encoding) {}

    public function extract(string $path, array $source): array
    {
        if (! is_file($path) || is_link($path) || realpath($path) !== $path || filesize($path) > 2 * 1024 * 1024
            || ! is_int($source['bytes'] ?? null) || filesize($path) !== $source['bytes']
            || ! is_string($source['sha256'] ?? null) || hash_file('sha256', $path) !== $source['sha256']
            || ! is_int($source['fetch_log_id'] ?? null) || $source['fetch_log_id'] < 1
            || empty($source['parser_version']) || empty($source['fetched_at'])) {
            throw new RuntimeException('School source identity, size or seal mismatch.');
        }
        $uri = parse_url($source['source_url'] ?? '');
        if (($uri['scheme'] ?? null) !== 'https' || ($uri['host'] ?? null) !== 'keirin.jp' || ($uri['path'] ?? null) !== '/pc/racerprofile') {
            throw new RuntimeException('Unsupported school profile source.');
        }
        $fetched = new DateTimeImmutable($source['fetched_at']);
        [$html] = $this->encoding->convertToUtf8(file_get_contents($path), $source['content_type'] ?? null);
        $profile = $this->parser->parse($html, $source['external_player_id']);
        if (hash_file('sha256', $path) !== $source['sha256']) {
            throw new RuntimeException('School source changed during projection.');
        }

        return ['version' => SchoolProfileParser::VERSION, 'external_player_id' => $profile->externalPlayerId,
            'graduation_period' => $profile->graduationPeriod,
            'STAT-02' => ['school_rank_raw' => $profile->schoolRank, 'official_cohort_size' => null, 'cohort_percentile' => null,
                'status' => $profile->schoolRank === null ? $this->missingOrInvalid($profile->originalFields['競輪学校順位'] ?? null) : 'PARTIAL',
                'reasons' => ['OFFICIAL_COHORT_SIZE_NOT_VERIFIED']],
            'STAT-03' => ['school_200m_seconds' => $profile->school200mSeconds, 'cohort_percentile' => null,
                'status' => $profile->school200mSeconds === null ? $this->missingOrInvalid($profile->originalFields['200mタイム'] ?? null) : 'PARTIAL',
                'reasons' => ['COHORT_DISTRIBUTION_AND_MEASUREMENT_DATE_NOT_VERIFIED']],
            'debut_date' => $profile->debutDate?->format('Y-m-d'), 'original_fields' => $profile->originalFields,
            'source' => ['fetch_log_id' => $source['fetch_log_id'], 'fetched_at' => $fetched->format(DATE_ATOM),
                'sha256' => $source['sha256'], 'bytes' => $source['bytes'], 'parser_version' => $source['parser_version']],
            'data_role' => 'RETROSPECTIVE_ONLY', 'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED',
            'points' => null, 'confidence' => null];
    }

    private function missingOrInvalid(?string $raw): string
    {
        return $raw === null || in_array($raw, ['', '-', '－'], true) ? 'MISSING_INPUT' : 'INVALID_INPUT';
    }

    public function store(string $output, array $projection): void
    {
        if (! str_starts_with($output, '/') || str_contains($output, '..') || is_link($output)
            || file_exists($output) || realpath(dirname($output)) !== dirname($output)) {
            throw new RuntimeException('An existing dedicated absolute output directory is required.');
        }
        $handle = fopen($output, 'xb');
        if ($handle === false) {
            throw new RuntimeException('School projection already exists or cannot be created.');
        }
        try {
            if (! chmod($output, 0600)) {
                throw new RuntimeException('Cannot restrict school projection permissions.');
            }
            $bytes = json_encode($projection, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('Incomplete school projection retained.');
            }
        } finally {
            fclose($handle);
        }
    }
}
