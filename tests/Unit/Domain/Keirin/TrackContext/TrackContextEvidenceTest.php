<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\TrackContext;

use App\Domain\Keirin\TrackContext\ExcerptEvidenceVerifier;
use App\Domain\Keirin\TrackContext\OfficialStructureParser;
use App\Domain\Keirin\TrackContext\TrackContextMaster;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TrackContextFixture;

final class TrackContextEvidenceTest extends TestCase
{
    private string $directory;

    private function distributedDirectory(): string
    {
        return dirname(__DIR__, 5).'/resources/data/keirin/track-context/v1';
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/track-evidence-test-'.bin2hex(random_bytes(8)).'/v1';
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

    public function test_distributed_excerpts_match_tracks_fields_periods_and_fixture(): void
    {
        $master = TrackContextMaster::load($this->distributedDirectory(), 'v1');
        self::assertCount(42, $master->tracks);
        self::assertSame(44, array_sum(array_map(fn (array $track): int => count($track['layouts']), $master->tracks)));
        $parser = new OfficialStructureParser;
        $html = file_get_contents($this->distributedDirectory().'/seibuen-structure.html');
        $fields = $parser->parse($html);
        self::assertSame($parser->parse(file_get_contents(__DIR__.'/../../../../Fixtures/TrackContext/actual/seibuen-structure.html')), $fields);
        self::assertStringContainsString('2022年6月28日～30日に西武園競輪場で開催される', $html);
        self::assertStringNotContainsString('最高上がり', $html);
        foreach (['2022-06-28', '2022-06-29', '2022-06-30'] as $date) {
            $layout = $master->resolve('keirin_jp', '26', $date)->layout;
            foreach ($fields as $name => $field) {
                foreach (['raw', 'value', 'unit'] as $attribute) {
                    self::assertSame($field[$attribute], $layout['fields'][$name][$attribute]);
                }
            }
            self::assertSame('200', $layout['fields']['segment_distance_m']['value']);
        }
        self::assertSame('UNKNOWN_LAYOUT_VERSION', $master->resolve('keirin_jp', '26', '2022-06-27')->status);
        self::assertSame('UNKNOWN_LAYOUT_VERSION', $master->resolve('keirin_jp', '26', '2022-07-01')->status);
        foreach ($master->tracks as $track) {
            self::assertSame('RESOLVED', $master->resolve('keirin_jp', $track['external_track_id'], '2012-12-31')->status);
            self::assertSame('UNKNOWN_LAYOUT_VERSION', $master->resolve('keirin_jp', $track['external_track_id'], '2013-01-01')->status);
        }
        self::assertSame('伊東温泉', $master->sources['structure2012-37']['evidence']['row']);
        self::assertSame('京都向日町', $master->sources['structure2012-54']['evidence']['row']);
        $kumamoto = $master->tracks['keirin_jp:87']['layouts'][1];
        foreach (['bank_circumference_m' => '400', 'straight_length_m' => '60.3', 'home_width_m' => '10.0', 'back_width_m' => '9.0', 'center_width_m' => '8.0'] as $key => $value) {
            self::assertSame($value, $kumamoto['fields'][$key]['value']);
        }
        self::assertSame('34°15′29″', $kumamoto['fields']['cant']['raw']);
        self::assertSame('UNKNOWN', $kumamoto['period']['status']);
        self::assertSame('250', $master->tracks['keirin_jp:87']['layouts'][0]['fields']['segment_distance_m']['value']);
        self::assertSame('200', $kumamoto['fields']['segment_distance_m']['value']);
        self::assertSame(ExcerptEvidenceVerifier::GLOSSARY, trim(file_get_contents($this->distributedDirectory().'/glossary.txt')));
        self::assertSame(ExcerptEvidenceVerifier::QA, trim(file_get_contents($this->distributedDirectory().'/qa.txt')));
    }

    #[DataProvider('tamperingCases')]
    public function test_resealed_semantic_tampering_is_rejected(string $case): void
    {
        $files = [];
        foreach (glob($this->distributedDirectory().'/*') as $path) {
            $name = basename($path);
            if ($name !== 'manifest.json') {
                $bytes = file_get_contents($path);
                $files[$name] = str_ends_with($name, '.json') ? json_decode($bytes, true, 128, JSON_THROW_ON_ERROR) : $bytes;
            }
        }
        $event = &$files['tracks.json']['keirin_jp:26']['layouts'][1];
        switch ($case) {
            case 'record_table':
                $table = (new OfficialStructureParser)->table($files['seibuen-structure.html'])->outerHtml();
                $files['seibuen-structure.html'] = str_replace($table, '<table class="hyo3"><tr><th>最高上がりタイム</th><td>synthetic record</td></tr></table>', $files['seibuen-structure.html']);
                break;
            case 'duplicate_table':
                $files['seibuen-structure.html'] .= (new OfficialStructureParser)->table($files['seibuen-structure.html'])->outerHtml();
                break;
            case 'event_numeric':
                $event['fields']['bank_circumference_m']['value'] = '500';
                $event['fields']['bank_circumference_m']['raw'] = '500m';
                $event['fields']['segment_distance_m']['value'] = '250';
                break;
            case 'table_numeric':
                $fields = &$files['tracks.json']['keirin_jp:11']['layouts'][0]['fields'];
                $fields['bank_circumference_m']['value'] = '500';
                $fields['bank_circumference_m']['raw'] = '500';
                $fields['segment_distance_m']['value'] = '250';
                break;
            case 'facility_numeric':
                $fields = &$files['tracks.json']['keirin_jp:87']['layouts'][1]['fields'];
                $fields['home_width_m']['value'] = '9.0';
                $fields['home_width_m']['raw'] = '9.0m';
                break;
            case 'dms_field_swap':
                $event['fields']['cant'] = $event['fields']['straight_slope'];
                break;
            case 'wrong_reference':
                $files['tracks.json']['keirin_jp:11']['layouts'][0]['fields']['bank_circumference_m']['source_refs'] = ['structure2012-12'];
                $files['tracks.json']['keirin_jp:11']['layouts'][0]['fields']['segment_distance_m']['source_refs'] = ['structure2012-12', 'glossary', 'qa'];
                break;
            case 'wrong_row':
                $files['sources.json']['structure2012-11']['evidence']['row'] = '青森';
                break;
            case 'wrong_column':
                $files['sources.json']['structure2012-11']['evidence']['columns']['cant'] = 'straight_slope';
                break;
            case 'missing_row':
                $files['structure2012.tsv'] = preg_replace('/^函館\t.*\n/mu', '', $files['structure2012.tsv']);
                break;
            case 'duplicate_row':
                $files['structure2012.tsv'] .= "函館\t400\t30°36′51″\t3°26′01″\t51.3\n";
                break;
            case 'missing_event_field':
                $files['seibuen-structure.html'] = str_replace('ホーム傾斜角', 'unrelated', $files['seibuen-structure.html']);
                break;
            case 'missing_event_period':
                $files['seibuen-structure.html'] = (new OfficialStructureParser)->table($files['seibuen-structure.html'])->outerHtml();
                break;
            case 'wrong_event_period':
                $event['period']['until'] = '2022-07-02';
                break;
            case 'missing_table_period':
                $files['structure2012.tsv'] = str_replace('平成24年12月31日現在', '', $files['structure2012.tsv']);
                break;
            case 'wrong_table_period':
                $files['tracks.json']['keirin_jp:11']['layouts'][0]['period']['until'] = '2023-01-01';
                break;
            case 'table_unit':
                $files['structure2012.tsv'] = str_replace('circumference_m', 'circumference_ft', $files['structure2012.tsv']);
                break;
            case 'event_unit':
                $files['seibuen-structure.html'] = str_replace('400m', '400ft', $files['seibuen-structure.html']);
                break;
            case 'facility_unit':
                $files['kumamoto-structure.txt'] = str_replace('400m', '400ft', $files['kumamoto-structure.txt']);
                break;
            case 'facility_missing_field':
                $files['kumamoto-structure.txt'] = str_replace("home_width_m\t10.0m\n", '', $files['kumamoto-structure.txt']);
                break;
            case 'facility_duplicate_field':
                $files['kumamoto-structure.txt'] = str_replace("home_width_m\t10.0m\n", "home_width_m\t10.0m\nhome_width_m\t10.0m\n", $files['kumamoto-structure.txt']);
                break;
            case 'glossary':
                $files['glossary.txt'] = str_replace('半周', '一周', $files['glossary.txt']);
                break;
            case 'qa':
                $files['qa.txt'] = str_replace('250m', '200m', $files['qa.txt']);
                break;
            case 'definition_reference':
                $files['definitions.json']['keirin-jp-final-back-half-lap-v1']['source_refs'] = ['seibuen-guide'];
                break;
            case 'direct_disguised_as_derived':
                $event['fields']['bank_circumference_m']['kind'] = 'DERIVED';
                break;
            case 'historical_direct_fallback':
                $event['fields']['bank_circumference_m']['source_refs'] = ['structure2012-26'];
                $event['fields']['bank_circumference_m']['raw'] = '400';
                $event['fields']['segment_distance_m']['source_refs'] = ['structure2012-26', 'glossary', 'qa'];
                break;
        }
        TrackContextFixture::write($this->directory, $files);
        // All member byte/hash seals really match; rejection must be semantic.
        $manifest = json_decode(file_get_contents($this->directory.'/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $name => $seal) {
            self::assertSame($seal['sha256'], hash_file('sha256', $this->directory.'/'.$name));
            self::assertSame($seal['bytes'], filesize($this->directory.'/'.$name));
        }
        $this->expectException(\Exception::class);
        TrackContextMaster::load($this->directory, 'v1');
    }

    public static function tamperingCases(): array
    {
        return array_map(fn (string $case): array => [$case], ['record_table', 'duplicate_table', 'event_numeric', 'table_numeric',
            'facility_numeric', 'dms_field_swap', 'wrong_reference', 'wrong_row', 'wrong_column', 'missing_row', 'duplicate_row',
            'missing_event_field', 'missing_event_period', 'wrong_event_period', 'missing_table_period', 'wrong_table_period',
            'table_unit', 'event_unit', 'facility_unit', 'facility_missing_field', 'facility_duplicate_field', 'glossary', 'qa',
            'definition_reference', 'direct_disguised_as_derived', 'historical_direct_fallback']);
    }

    public function test_structural_table_selection_is_by_labels_not_position(): void
    {
        $parser = new OfficialStructureParser;
        $table = file_get_contents(__DIR__.'/../../../../Fixtures/TrackContext/actual/seibuen-structure.html');
        $record = '<table class="hyo3"><tr><th>最高上がりタイム</th><td>synthetic only</td></tr></table>';
        self::assertSame($parser->parse($table), $parser->parse($record.$table.$record));
        self::assertSame($parser->parse($table), $parser->parse($table.$record));
        $this->expectException(InvalidArgumentException::class);
        $parser->parse($table.$record.$table);
    }

    public function test_duplicate_label_in_selected_table_is_rejected(): void
    {
        $table = file_get_contents(__DIR__.'/../../../../Fixtures/TrackContext/actual/seibuen-structure.html');
        $this->expectException(InvalidArgumentException::class);
        (new OfficialStructureParser)->parse(str_replace('</table>', '<tr><th>周長</th><td>400m</td></tr></table>', $table));
    }
}
