<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\TrackContext;

use App\Domain\Keirin\TrackContext\HistoricalStructureExcerptParser;
use App\Domain\Keirin\TrackContext\TrackContextMaster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HistoricalTrackContextTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/historical-track-'.bin2hex(random_bytes(8)).'/v2';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
            rmdir(dirname($this->directory));
        }
    }

    private function distributed(string $version = 'v2'): string
    {
        return dirname(__DIR__, 5).'/resources/data/keirin/track-context/'.$version;
    }

    private function files(): array
    {
        $files = [];
        foreach (glob($this->distributed().'/*') as $path) {
            if (basename($path) !== 'manifest.json') {
                $bytes = file_get_contents($path);
                $files[basename($path)] = str_ends_with($path, '.json') ? json_decode($bytes, true, 128, JSON_THROW_ON_ERROR) : $bytes;
            }
        }

        return $files;
    }

    private function write(array $files): void
    {
        mkdir($this->directory, 0700, true);
        $manifest = ['version' => 'v2', 'files' => []];
        foreach ($files as $name => $value) {
            $bytes = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            file_put_contents($this->directory.'/'.$name, $bytes);
            $manifest['files'][$name] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        foreach ($manifest['files'] as $name => $seal) {
            self::assertSame($seal['sha256'], hash_file('sha256', $this->directory.'/'.$name));
        }
    }

    public function test_real_v2_excerpts_have_bound_identity_values_and_event_only_periods(): void
    {
        $master = TrackContextMaster::load($this->distributed(), 'v2');
        $files = $this->files();
        $parser = new HistoricalStructureExcerptParser;
        self::assertCount(42, $master->tracks);
        self::assertCount(42, $files['venue-review.json']);
        self::assertSame(89, array_sum(array_map(fn ($t) => count($t['layouts']), $master->tracks)));
        foreach ($master->tracks as $track) {
            $source = $master->sources['annual2023-'.$track['external_track_id']];
            $parsed = $parser->parse($files[$source['excerpt_file']], $source['evidence']);
            self::assertNull($parsed['period']);
            $layout = array_values(array_filter($track['layouts'], fn ($l) => $l['layout_version'] === 'annual2023-'.$track['external_track_id']))[0];
            self::assertSame('UNKNOWN', $layout['period']['status']);
            foreach ($parsed['fields'] as $name => $field) {
                foreach (['value', 'raw', 'unit'] as $key) {
                    self::assertSame($field[$key], $layout['fields'][$name][$key]);
                }
            }
        }
        foreach (['2023-06-29', '2023-06-30', '2023-07-01', '2023-07-02'] as $date) {
            $resolution = $master->resolve('keirin_jp', '22', $date);
            self::assertSame('RESOLVED', $resolution->status);
            self::assertSame('335', $resolution->layout['fields']['bank_circumference_m']['value']);
            self::assertSame('167.5', $resolution->layout['fields']['segment_distance_m']['value']);
            self::assertSame(129600, $resolution->layout['fields']['cant']['value']['arcseconds']);
            self::assertSame('MISSING', $resolution->layout['fields']['home_width_m']['status']);
        }
        foreach (['2024-11-04', '2024-11-05', '2024-11-06', '2024-11-08', '2024-11-09', '2024-11-10', '2024-11-11'] as $date) {
            $resolution = $master->resolve('keirin_jp', '35', $date);
            self::assertSame('RESOLVED', $resolution->status);
            self::assertSame('200', $resolution->layout['fields']['segment_distance_m']['value']);
            self::assertSame('54.2', $resolution->layout['fields']['straight_length_m']['value']);
        }
        foreach (['2024-10-08', '2024-11-03', '2024-11-07', '2024-11-12', '2024-12-31'] as $date) {
            self::assertSame('UNKNOWN_LAYOUT_VERSION', $master->resolve('keirin_jp', '35', $date)->status);
        }
        foreach (['2023-06-28', '2023-07-03'] as $date) {
            self::assertSame('UNKNOWN_LAYOUT_VERSION', $master->resolve('keirin_jp', '22', $date)->status);
        }
        self::assertSame('UNKNOWN_LAYOUT_VERSION', $master->resolve('keirin_jp', '87', '2024-07-20')->status);
    }

    public function test_v1_bytes_and_all_previous_observations_remain_unchanged(): void
    {
        self::assertSame('d987a6eed079a8370492e57cc92e51611145e42705ae6c68866478e32e35da8a', hash_file('sha256', $this->distributed('v1').'/manifest.json'));
        $v1 = TrackContextMaster::load($this->distributed('v1'), 'v1');
        $v2 = TrackContextMaster::load($this->distributed(), 'v2');
        foreach ($v1->tracks as $key => $track) {
            self::assertSame($track['layouts'], array_slice($v2->tracks[$key]['layouts'], 0, count($track['layouts'])));
        }
        foreach ($v1->sources as $id => $source) {
            self::assertSame($source, $v2->sources[$id]);
        }
        self::assertSame($v1->definitions, $v2->definitions);
        foreach (['2022-06-28', '2022-06-29', '2022-06-30'] as $date) {
            self::assertSame($v1->resolve('keirin_jp', '26', $date)->layout, $v2->resolve('keirin_jp', '26', $date)->layout);
        }
    }

    #[DataProvider('tampering')]
    public function test_resealed_structural_and_temporal_tampering_is_rejected(string $case): void
    {
        $files = $this->files();
        $layout = &$files['tracks.json']['keirin_jp:22']['layouts'][2];
        switch ($case) {
            case 'numeric':
                $layout['fields']['bank_circumference_m']['value'] = '400';
                $layout['fields']['bank_circumference_m']['raw'] = '400m';
                $layout['fields']['segment_distance_m']['value'] = '200';
                break;
            case 'wrong_track':
                $files['sources.json']['maebashi-20230629-event']['evidence']['track_id'] = '35';
                break;
            case 'wrong_name':
                $files['sources.json']['maebashi-20230629-event']['evidence']['row'] = '平塚';
                break;
            case 'wrong_column':
                $files['sources.json']['annual2023-11']['evidence']['columns']['cant'] = '直線部カント';
                break;
            case 'wrong_row':
                $files['sources.json']['annual2023-11']['evidence']['row'] = '青森';
                break;
            case 'wrong_table':
                $files['annual2023-structure.tsv'] = str_replace('■バンクレコード', '■別表', $files['annual2023-structure.tsv']);
                break;
            case 'missing_row':
                $files['annual2023-structure.tsv'] = preg_replace('/^函館\t.*\n/mu', '', $files['annual2023-structure.tsv']);
                break;
            case 'duplicate_row':
                $files['annual2023-structure.tsv'] .= "函館\t400\t30°36′51″\t3°26′01″\t51.3ｍ\n";
                break;
            case 'unit':
                $files['maebashi2023-program.txt'] = str_replace('335m', '335ft', $files['maebashi2023-program.txt']);
                break;
            case 'missing_date':
                $files['maebashi2023-program.txt'] = str_replace('2023.6.29THU/30FRI/7.1SAT/2SUN', '', $files['maebashi2023-program.txt']);
                break;
            case 'weekday':
                $files['maebashi2023-program.txt'] = str_replace('6.29THU', '6.28THU', $files['maebashi2023-program.txt']);
                break;
            case 'extend_period':
                $layout['period']['until'] = '2023-07-04';
                break;
            case 'annual_as_year':
                $files['tracks.json']['keirin_jp:11']['layouts'][1]['period'] = ['status' => 'CONFIRMED', 'from' => '2023-01-01', 'until' => '2024-01-01', 'source_refs' => ['annual2023-11'], 'evidence' => 'synthetic unsupported year'];
                break;
            case 'bridge_gap':
                $files['tracks.json']['keirin_jp:35']['layouts'][2]['period']['until'] = '2024-11-12';
                break;
            case 'event_index':
                $files['sources.json']['hiratsuka-202411-event-0']['evidence']['event_index'] = 1;
                break;
            case 'missing_field':
                $files['maebashi2023-program.txt'] = str_replace("最大カント\t36度0分0秒\n", '', $files['maebashi2023-program.txt']);
                break;
            case 'duplicate_field':
                $files['maebashi2023-program.txt'] .= "バンク周長\t335m\n";
                break;
            case 'wrong_source_period':
                $layout['fields']['bank_circumference_m']['source_refs'] = ['annual2023-22'];
                $layout['fields']['bank_circumference_m']['raw'] = '335';
                $layout['fields']['segment_distance_m']['source_refs'] = ['annual2023-22', 'glossary', 'qa'];
                break;
            case 'missing_excerpt':
                unset($files['maebashi2023-program.txt']);
                break;
        }
        $this->write($files);
        $this->expectException(\Exception::class);
        TrackContextMaster::load($this->directory, 'v2');
    }

    public static function tampering(): array
    {
        return array_map(fn ($case) => [$case], ['numeric', 'wrong_track', 'wrong_name', 'wrong_column', 'wrong_row', 'wrong_table',
            'missing_row', 'duplicate_row', 'unit', 'missing_date', 'weekday', 'extend_period', 'annual_as_year', 'bridge_gap',
            'event_index', 'missing_field', 'duplicate_field', 'wrong_source_period', 'missing_excerpt']);
    }

    public function test_explicit_changed_structure_and_gap_are_not_interpolated_or_selected_by_latest(): void
    {
        $files = $this->files();
        $layout = $files['tracks.json']['keirin_jp:22']['layouts'][2];
        $source = $files['sources.json']['maebashi-20230629-event'];
        $source['excerpt_file'] = 'synthetic-renovation.txt';
        $files['synthetic-renovation.txt'] = str_replace(['2023.6.29THU/30FRI/7.1SAT/2SUN', '335m'], ['2023.7.6THU/7FRI/7.8SAT/9SUN', '400m'], $files['maebashi2023-program.txt']);
        $files['sources.json']['synthetic-renovation'] = $source;
        $layout['layout_version'] = 'synthetic-renovation';
        $layout['period'] = ['status' => 'CONFIRMED', 'from' => '2023-07-06', 'until' => '2023-07-10', 'source_refs' => ['synthetic-renovation'], 'evidence' => 'Synthetic new event, no real renovation claim'];
        foreach ($layout['fields'] as &$field) {
            $field['source_refs'] = array_map(fn ($r) => $r === 'maebashi-20230629-event' ? 'synthetic-renovation' : $r, $field['source_refs']);
        }
        unset($field);
        $layout['fields']['bank_circumference_m']['value'] = '400';
        $layout['fields']['bank_circumference_m']['raw'] = '400m';
        $layout['fields']['segment_distance_m']['value'] = '200';
        $files['tracks.json']['keirin_jp:22']['layouts'][] = $layout;
        $this->write($files);
        $master = TrackContextMaster::load($this->directory, 'v2');
        self::assertSame('167.5', $master->resolve('keirin_jp', '22', '2023-07-02')->layout['fields']['segment_distance_m']['value']);
        foreach (['2023-07-03', '2023-07-04', '2023-07-05', '2023-07-10'] as $date) {
            self::assertSame('UNKNOWN_LAYOUT_VERSION', $master->resolve('keirin_jp', '22', $date)->status);
        }
        self::assertSame('200', $master->resolve('keirin_jp', '22', '2023-07-06')->layout['fields']['segment_distance_m']['value']);
    }

    public function test_overlapping_evidenced_periods_remain_conflicting(): void
    {
        $files = $this->files();
        $layout = $files['tracks.json']['keirin_jp:22']['layouts'][2];
        $layout['layout_version'] = 'synthetic-overlap';
        $files['tracks.json']['keirin_jp:22']['layouts'][] = $layout;
        $this->write($files);
        self::assertSame('SOURCE_CONFLICT', TrackContextMaster::load($this->directory, 'v2')->resolve('keirin_jp', '22', '2023-07-01')->status);
    }
}
