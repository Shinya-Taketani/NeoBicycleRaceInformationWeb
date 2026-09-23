<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\TrackContext\StructureValues;
use App\Domain\Keirin\TrackContext\TrackContextMaster;

final class TrackContextFixture
{
    public static function files(): array
    {
        $fields = [];
        foreach (TrackContextMaster::FIELDS as $key => $unit) {
            $fields[$key] = ['status' => 'MISSING', 'value' => null, 'unit' => $unit, 'raw' => null,
                'precision' => null, 'source_refs' => [], 'kind' => null, 'reason' => 'SYNTHETIC_MISSING'];
        }
        foreach (['bank_circumference_m' => '400', 'segment_distance_m' => '200'] as $key => $value) {
            $fields[$key] = ['status' => 'CONFIRMED', 'value' => $value, 'unit' => 'm', 'raw' => $value.'m',
                'precision' => null, 'source_refs' => ['synthetic'], 'kind' => $key === 'segment_distance_m' ? 'DERIVED' : 'DIRECT'];
        }
        $layout = ['layout_version' => 'synthetic-a', 'measurement_definition_id' => 'half-lap', 'fields' => $fields,
            'period' => ['status' => 'CONFIRMED', 'from' => '2022-01-01', 'until' => '2024-01-01', 'source_refs' => ['synthetic'], 'evidence' => 'Artificial period, not real history.']];

        return [
            'sources.json' => ['synthetic' => ['url' => 'https://example.invalid/structure', 'fetched_at' => '2025-01-01T00:00:00+09:00',
                'published_at' => null, 'raw_reference' => 'synthetic.txt', 'raw_sha256' => hash('sha256', 'synthetic'),
                'raw_bytes' => 9, 'excerpt_file' => 'synthetic.txt', 'normalization_version' => TrackContextMaster::NORMALIZATION_VERSION]],
            'definitions.json' => ['half-lap' => ['id' => 'half-lap', 'status' => 'CONFIRMED', 'kind' => 'HALF_LAP', 'source_refs' => ['synthetic'], 'precision_seconds' => null]],
            'tracks.json' => ['keirin_jp:11' => ['source' => 'keirin_jp', 'external_track_id' => '11', 'name' => 'SYNTHETIC', 'layouts' => [$layout]]],
            'synthetic.txt' => 'synthetic',
        ];
    }

    public static function circumference(array &$files, string $value, int $layout = 0): void
    {
        $fields = &$files['tracks.json']['keirin_jp:11']['layouts'][$layout]['fields'];
        $fields['bank_circumference_m']['value'] = $value;
        $fields['bank_circumference_m']['raw'] = $value.'m';
        $fields['segment_distance_m']['value'] = (string) StructureValues::positiveDecimal($value)->dividedByExact('2');
    }

    public static function write(string $directory, array $files): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $manifest = ['version' => 'v1', 'files' => []];
        foreach ($files as $file => $value) {
            $bytes = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            file_put_contents($directory.'/'.$file, $bytes);
            $manifest['files'][$file] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
