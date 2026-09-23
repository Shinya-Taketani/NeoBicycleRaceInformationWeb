<?php

declare(strict_types=1);

namespace App\Domain\Keirin\TrackContext;

use InvalidArgumentException;
use RuntimeException;

final readonly class TrackContextMaster
{
    public const NORMALIZATION_VERSION = 'track-context-v1';

    public const FIELDS = [
        'bank_circumference_m' => 'm', 'straight_length_m' => 'm', 'cant' => 'DMS',
        'indoor_outdoor' => 'category', 'home_width_m' => 'm', 'back_width_m' => 'm',
        'center_width_m' => 'm', 'straight_slope' => 'DMS', 'segment_distance_m' => 'm',
    ];

    private function __construct(public string $version, public string $manifestSha256, public array $sources, public array $definitions, public array $tracks) {}

    public static function load(string $directory, string $version): self
    {
        if (! preg_match('/\Av[1-9][0-9]*\z/', $version) || basename($directory) !== $version) {
            throw new InvalidArgumentException('An explicit matching master version is required.');
        }
        $manifestBytes = self::read($directory.'/manifest.json');
        $manifest = self::json($manifestBytes);
        if (($manifest['version'] ?? null) !== $version || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Invalid master manifest.');
        }
        $files = [];
        foreach ($manifest['files'] as $path => $seal) {
            if (! is_string($path) || ! preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $path) || $path === 'manifest.json') {
                throw new RuntimeException('Invalid manifest member path.');
            }
            if (is_link($directory.'/'.$path)) {
                throw new RuntimeException('Symlink master member.');
            }
            $bytes = self::read($directory.'/'.$path);
            if (($seal['bytes'] ?? null) !== strlen($bytes) || ($seal['sha256'] ?? null) !== hash('sha256', $bytes)) {
                throw new RuntimeException('Master member integrity mismatch: '.$path);
            }
            $files[$path] = $bytes;
        }
        foreach (['sources.json', 'definitions.json', 'tracks.json'] as $required) {
            if (! isset($files[$required])) {
                throw new RuntimeException('Missing sealed master member: '.$required);
            }
        }
        $sources = self::json($files['sources.json']);
        $definitions = self::json($files['definitions.json']);
        $tracks = self::json($files['tracks.json']);
        $master = new self($version, hash('sha256', $manifestBytes), $sources, $definitions, $tracks);
        $master->validate($files);
        (new ExcerptEvidenceVerifier)->verify($master, $files);

        return $master;
    }

    public function resolve(string $source, string $externalTrackId, string $date): LayoutResolution
    {
        StructureValues::date($date);
        $layouts = $this->tracks[$source.':'.$externalTrackId]['layouts'] ?? [];
        $candidates = array_values(array_filter($layouts, static fn (array $l): bool => $l['period']['status'] === 'CONFIRMED'
            && $date >= $l['period']['from'] && $date < $l['period']['until']));
        $versions = array_column($candidates, 'layout_version');
        if (count($candidates) !== 1) {
            return new LayoutResolution(count($candidates) > 1 ? 'SOURCE_CONFLICT' : 'UNKNOWN_LAYOUT_VERSION', $this->version, $source, $externalTrackId, $date, candidateVersions: $versions);
        }
        $layout = $candidates[0];
        if (in_array('CONFLICT', array_column($layout['fields'], 'status'), true)) {
            return new LayoutResolution('SOURCE_CONFLICT', $this->version, $source, $externalTrackId, $date, candidateVersions: $versions);
        }

        return new LayoutResolution('RESOLVED', $this->version, $source, $externalTrackId, $date, $layout, $this->definitions[$layout['measurement_definition_id']], $versions);
    }

    private function validate(array $files): void
    {
        foreach ($this->sources as $id => $source) {
            if (! is_string($id) || ! is_array($source) || ! filter_var($source['url'] ?? null, FILTER_VALIDATE_URL)
                || ! in_array(parse_url($source['url'], PHP_URL_SCHEME), ['https', 'http'], true)
                || ! is_string($source['fetched_at'] ?? null) || strtotime($source['fetched_at']) === false
                || ! array_key_exists('published_at', $source)
                || ($source['published_at'] !== null && (! is_string($source['published_at']) || strtotime($source['published_at']) === false))
                || ! is_string($source['raw_reference'] ?? null) || $source['raw_reference'] === ''
                || ! preg_match('/\A[a-f0-9]{64}\z/', $source['raw_sha256'] ?? '')
                || ! is_int($source['raw_bytes'] ?? null) || $source['raw_bytes'] <= 0
                || ! isset($files[$source['excerpt_file'] ?? ''])
                || ($source['normalization_version'] ?? null) !== self::NORMALIZATION_VERSION) {
                throw new RuntimeException('Invalid source provenance: '.$id);
            }
        }
        foreach ($this->definitions as $id => $definition) {
            if (($definition['id'] ?? null) !== $id || ! in_array($definition['status'] ?? null, ['CONFIRMED', 'UNKNOWN'], true)
                || ! in_array($definition['kind'] ?? null, ['HALF_LAP', 'EXPLICIT_DISTANCE', 'UNKNOWN'], true)
                || ! array_key_exists('precision_seconds', $definition)) {
                throw new RuntimeException('Invalid measurement definition.');
            }
            $this->refs($definition['source_refs'] ?? null, $definition['status'] === 'CONFIRMED');
            if ($definition['precision_seconds'] !== null) {
                StructureValues::positiveDecimal($definition['precision_seconds']);
            }
        }
        foreach ($this->tracks as $key => $track) {
            if (! is_string($track['source'] ?? null) || ! is_string($track['external_track_id'] ?? null)
                || ! preg_match('/\A[0-9]+\z/', $track['external_track_id'])
                || $key !== $track['source'].':'.$track['external_track_id'] || ! is_array($track['layouts'] ?? null)
                || ! array_is_list($track['layouts'])) {
                throw new RuntimeException('Invalid track identity.');
            }
            $versions = [];
            foreach ($track['layouts'] as $layout) {
                $version = $layout['layout_version'] ?? null;
                if (! is_string($version) || $version === '' || isset($versions[$version])
                    || ! isset($this->definitions[$layout['measurement_definition_id'] ?? ''])
                    || ! is_array($layout['fields'] ?? null) || ! is_array($layout['period'] ?? null)) {
                    throw new RuntimeException('Invalid layout identity/definition.');
                }
                $versions[$version] = true;
                $period = $layout['period'];
                $this->refs($period['source_refs'] ?? null, ($period['status'] ?? null) === 'CONFIRMED');
                if (($period['status'] ?? null) === 'CONFIRMED') {
                    if (StructureValues::date($period['from'] ?? null) >= StructureValues::date($period['until'] ?? null)
                        || ! is_string($period['evidence'] ?? null) || $period['evidence'] === '') {
                        throw new RuntimeException('Invalid evidenced half-open period.');
                    }
                } elseif (($period['status'] ?? null) !== 'UNKNOWN' || ($period['from'] ?? null) !== null || ($period['until'] ?? null) !== null) {
                    throw new RuntimeException('Unknown period cannot provide fallback dates.');
                }
                $keys = array_keys($layout['fields']);
                $expected = array_keys(self::FIELDS);
                sort($keys);
                sort($expected);
                if ($keys !== $expected) {
                    throw new RuntimeException('Missing or unexpected structural field.');
                }
                foreach (self::FIELDS as $name => $unit) {
                    $field = $layout['fields'][$name];
                    if (! in_array($field['status'] ?? null, ['CONFIRMED', 'MISSING', 'CONFLICT'], true)
                        || ! array_key_exists('value', $field) || ($field['unit'] ?? null) !== $unit
                        || ! array_key_exists('raw', $field) || ! array_key_exists('precision', $field)) {
                        throw new RuntimeException('Invalid field metadata: '.$name);
                    }
                    $this->refs($field['source_refs'] ?? null, $field['status'] !== 'MISSING');
                    if ($field['status'] !== 'CONFIRMED') {
                        if ($field['value'] !== null || ! is_string($field['reason'] ?? null) || $field['reason'] === '') {
                            throw new RuntimeException('Unconfirmed field must be null with a reason.');
                        }

                        continue;
                    }
                    if (! in_array($field['kind'] ?? null, ['DIRECT', 'DERIVED'], true) || ! is_string($field['raw']) || $field['raw'] === '') {
                        throw new RuntimeException('Confirmed field requires raw evidence and derivation kind.');
                    }
                    if ($unit === 'm') {
                        $number = StructureValues::positiveDecimal($field['value']);
                        if ($field['kind'] === 'DIRECT') {
                            $raw = mb_convert_kana($field['raw'], 'as', 'UTF-8');
                            if (! preg_match('/\A([0-9]+(?:\.[0-9]+)?)(?:m)?\z/', $raw, $match)
                                || ! $number->isEqualTo($match[1])) {
                                throw new RuntimeException('Numeric value does not match published text.');
                            }
                        }
                    } elseif ($unit === 'DMS') {
                        if ($field['value'] !== StructureValues::dms($field['raw'])) {
                            throw new RuntimeException('DMS does not match raw evidence.');
                        }
                    } elseif (! in_array($field['value'], ['INDOOR', 'OUTDOOR'], true)) {
                        throw new RuntimeException('Invalid indoor/outdoor classification.');
                    }
                }
                $distance = $layout['fields']['segment_distance_m'];
                $definition = $this->definitions[$layout['measurement_definition_id']];
                if ($distance['status'] === 'CONFIRMED') {
                    if ($definition['status'] !== 'CONFIRMED') {
                        throw new RuntimeException('Unconfirmed measurement definition with a distance.');
                    }
                    if ($definition['kind'] === 'HALF_LAP') {
                        $circumference = $layout['fields']['bank_circumference_m'];
                        if ($circumference['status'] !== 'CONFIRMED' || $distance['kind'] !== 'DERIVED'
                            || ! StructureValues::positiveDecimal($circumference['value'])->dividedByExact('2')->isEqualTo($distance['value'])
                            || array_diff(array_merge($circumference['source_refs'], $definition['source_refs']), $distance['source_refs']) !== []) {
                            throw new RuntimeException('Half-lap distance/lineage mismatch.');
                        }
                    } elseif ($definition['kind'] !== 'EXPLICIT_DISTANCE' || $distance['kind'] !== 'DIRECT') {
                        throw new RuntimeException('Unsupported distance derivation.');
                    }
                }
            }
        }
    }

    private function refs(mixed $refs, bool $required): void
    {
        if (! is_array($refs) || ! array_is_list($refs) || ($required && $refs === [])) {
            throw new RuntimeException('Missing evidence references.');
        }
        foreach ($refs as $ref) {
            if (! is_string($ref) || ! isset($this->sources[$ref])) {
                throw new RuntimeException('Dangling evidence reference.');
            }
        }
    }

    private static function read(string $path): string
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 8 * 1024 * 1024) {
            throw new RuntimeException('Missing/unreadable/oversize master file: '.$path);
        }

        return file_get_contents($path);
    }

    private static function json(string $bytes): array
    {
        $object = json_decode($bytes, false, 128, JSON_THROW_ON_ERROR);
        if (! $object instanceof \stdClass) {
            throw new RuntimeException('Expected a JSON object.');
        }

        return json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
    }
}
