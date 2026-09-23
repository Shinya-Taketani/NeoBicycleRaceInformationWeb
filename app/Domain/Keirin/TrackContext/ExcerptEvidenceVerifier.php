<?php

declare(strict_types=1);

namespace App\Domain\Keirin\TrackContext;

use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/** The four sealed v1 excerpt formats, not a network or general-purpose scraping parser. */
final class ExcerptEvidenceVerifier
{
    public const TABLE_COLUMNS = ['bank_circumference_m' => 'circumference_m', 'cant' => 'cant', 'straight_slope' => 'straight_slope', 'straight_length_m' => 'straight_length_m'];

    public const EVENT_COLUMNS = ['bank_circumference_m' => '周長', 'straight_slope' => 'ホーム傾斜角', 'cant' => 'センター傾斜角'];

    public const FACILITY_COLUMNS = ['bank_circumference_m', 'straight_length_m', 'cant', 'straight_slope', 'home_width_m', 'back_width_m', 'center_width_m'];

    public const GLOSSARY = '最終周回のバック・ストレッチ・ラインから決勝線までの半周のタイム。';

    public const QA = '上がりタイムとは、最終周回バック・ストレッチ・ラインから決勝線までの半周タイム（500mバンクであれば最終の250mのタイム）です。';

    private const NAME_ALIASES = ['伊東' => '伊東温泉', '向日町' => '京都向日町'];

    public function verify(TrackContextMaster $master, array $files): void
    {
        $parsed = [];
        foreach ($master->sources as $id => $source) {
            $evidence = $source['evidence'] ?? [];
            $bytes = $files[$source['excerpt_file']];
            $parsed[$id] = match ($evidence['format'] ?? null) {
                'STRUCTURE_TABLE_TSV' => $this->table($bytes, $evidence),
                'SEIBUEN_EVENT_HTML' => $this->event($bytes, $evidence),
                'KUMAMOTO_FACILITY_TEXT' => $this->facility($bytes, $evidence),
                'HALF_LAP_GLOSSARY', 'HALF_LAP_QA' => $this->definition($bytes, $evidence),
                default => throw new RuntimeException('Unsupported excerpt format: '.$id),
            };
        }
        foreach ($master->definitions as $definition) {
            if ($definition['status'] !== 'CONFIRMED') {
                continue;
            }
            foreach ($definition['source_refs'] as $ref) {
                if (($parsed[$ref]['definition'] ?? null) !== $definition['kind']) {
                    throw new RuntimeException('Measurement definition differs from excerpt.');
                }
            }
        }
        foreach ($master->tracks as $track) {
            foreach ($track['layouts'] as $layout) {
                $period = $layout['period'];
                foreach ($period['source_refs'] as $ref) {
                    $this->identity($track, $parsed[$ref]);
                    if ($period['status'] === 'CONFIRMED' && ($parsed[$ref]['period'] ?? null) !== [$period['from'], $period['until']]) {
                        throw new RuntimeException('Period differs from excerpt.');
                    }
                }
                foreach ($layout['fields'] as $key => $field) {
                    if ($field['status'] !== 'CONFIRMED') {
                        continue;
                    }
                    if ($field['kind'] === 'DERIVED') {
                        if ($key !== 'segment_distance_m' || $master->definitions[$layout['measurement_definition_id']]['kind'] !== 'HALF_LAP') {
                            throw new RuntimeException('Unsupported derived structural field.');
                        }

                        continue;
                    }
                    foreach ($field['source_refs'] as $ref) {
                        $source = $parsed[$ref];
                        $this->identity($track, $source);
                        $published = $source['fields'][$key] ?? null;
                        if ($published === null || $published['unit'] !== $field['unit']
                            || $this->text($published['raw']) !== $this->text($field['raw'])
                            || ($field['unit'] === 'm'
                                ? ! StructureValues::positiveDecimal($published['value'])->isEqualTo($field['value'])
                                : $published['value'] !== $field['value'])) {
                            throw new RuntimeException('Direct value differs from excerpt: '.$ref.'/'.$key);
                        }
                        // A different source period cannot silently supply a current/historical value.
                        if ($period['status'] === 'CONFIRMED' && ($source['period'] ?? null) !== [$period['from'], $period['until']]) {
                            throw new RuntimeException('Direct source period differs from layout.');
                        }
                    }
                }
            }
        }
    }

    private function identity(array $track, array $source): void
    {
        if ($track['source'] !== 'keirin_jp' || ($source['track_id'] ?? null) !== $track['external_track_id']
            || ($source['row'] ?? null) !== (self::NAME_ALIASES[$track['name']] ?? $track['name'])) {
            throw new RuntimeException('Excerpt track/row identity mismatch.');
        }
    }

    private function table(string $bytes, array $evidence): array
    {
        $lines = explode("\n", rtrim($bytes, "\n"));
        if (count($lines) < 4 || $lines[0] !== 'Official 2012 table, PDF physical page 11 / printed page 9. Structural columns only.'
            || $lines[2] !== "name\tcircumference_m\tcant\tstraight_slope\tstraight_length_m"
            || ($evidence['columns'] ?? null) !== self::TABLE_COLUMNS
            || ! preg_match('/\A(平成|令和)([1-9][0-9]*)年([0-9]{1,2})月([0-9]{1,2})日現在\z/u', $lines[1], $m)) {
            throw new RuntimeException('Invalid structural table header/period/columns.');
        }
        $date = StructureValues::date(sprintf('%04d-%02d-%02d', (int) $m[2] + ($m[1] === '平成' ? 1988 : 2018), (int) $m[3], (int) $m[4]));
        $rows = [];
        foreach (array_slice($lines, 3) as $line) {
            $cells = explode("\t", $line);
            if (count($cells) !== 5 || isset($rows[$cells[0]])) {
                throw new RuntimeException('Missing/duplicate structural table row or column.');
            }
            $rows[$cells[0]] = array_combine(array_values(self::TABLE_COLUMNS), array_slice($cells, 1));
        }
        $row = $rows[$evidence['row'] ?? ''] ?? throw new RuntimeException('Named structural row absent.');
        $fields = [];
        foreach (self::TABLE_COLUMNS as $key => $column) {
            $raw = $row[$column];
            $fields[$key] = $this->field($key, $key === 'straight_length_m' ? $raw.'ｍ' : $raw, false);
        }

        return [...$evidence, 'fields' => $fields, 'period' => [$date, $this->nextDay($date)]];
    }

    private function event(string $bytes, array $evidence): array
    {
        $meta = (new Crawler($bytes))->filter('meta[name="description"]');
        if ($meta->count() !== 1 || ($evidence['columns'] ?? null) !== self::EVENT_COLUMNS
            || ! preg_match('/\A([0-9]{4})年([0-9]{1,2})月([0-9]{1,2})日～([0-9]{1,2})日に([^、]+)競輪場で開催される、.+\z/u', $meta->attr('content'), $m)
            || ($evidence['row'] ?? null) !== $m[5] || $m[5] !== '西武園' || ($evidence['track_id'] ?? null) !== '26') {
            throw new RuntimeException('Missing/invalid event identity or date evidence.');
        }
        $from = StructureValues::date(sprintf('%s-%02d-%02d', $m[1], (int) $m[2], (int) $m[3]));
        $last = StructureValues::date(sprintf('%s-%02d-%02d', $m[1], (int) $m[2], (int) $m[4]));
        if ($from > $last) {
            throw new RuntimeException('Reversed event dates.');
        }

        return [...$evidence, 'fields' => (new OfficialStructureParser)->parse($bytes), 'period' => [$from, $this->nextDay($last)]];
    }

    private function facility(string $bytes, array $evidence): array
    {
        $lines = explode("\n", rtrim($bytes, "\n"));
        if (array_shift($lines) !== 'Official Kumamoto facility guide: current observation, NOT a historical interval.'
            || array_pop($lines) !== '2024年6月にリニューアルオープン'
            || ($evidence['track_id'] ?? null) !== '87' || ($evidence['row'] ?? null) !== '熊本'
            || ($evidence['columns'] ?? null) !== array_combine(self::FACILITY_COLUMNS, self::FACILITY_COLUMNS)) {
            throw new RuntimeException('Invalid Kumamoto facility identity/columns/context.');
        }
        $fields = [];
        foreach ($lines as $line) {
            $cells = explode("\t", $line);
            if (count($cells) !== 2 || ! in_array($cells[0], self::FACILITY_COLUMNS, true) || isset($fields[$cells[0]])) {
                throw new RuntimeException('Invalid/duplicate facility field.');
            }
            $fields[$cells[0]] = $this->field($cells[0], $cells[1], true);
        }
        if (count($fields) !== count(self::FACILITY_COLUMNS)) {
            throw new RuntimeException('Missing facility field.');
        }

        return [...$evidence, 'fields' => $fields, 'period' => null];
    }

    private function definition(string $bytes, array $evidence): array
    {
        $quote = $evidence['format'] === 'HALF_LAP_GLOSSARY' ? self::GLOSSARY : self::QA;
        if ($this->text($bytes) !== $this->text($quote)) {
            throw new RuntimeException('Half-lap definition excerpt mismatch.');
        }

        return ['definition' => 'HALF_LAP'];
    }

    private function field(string $key, string $raw, bool $requireMetres): array
    {
        $unit = TrackContextMaster::FIELDS[$key];
        if ($unit === 'DMS') {
            $value = StructureValues::dms($raw);
        } else {
            $pattern = $requireMetres ? '/\A([0-9]+(?:\.[0-9]+)?)m\z/' : '/\A([0-9]+(?:\.[0-9]+)?)(?:m)?\z/';
            if (! preg_match($pattern, $this->text($raw), $m)) {
                throw new RuntimeException('Invalid published decimal/unit.');
            }
            StructureValues::positiveDecimal($m[1]);
            $value = $m[1];
        }

        return ['raw' => $raw, 'value' => $value, 'unit' => $unit];
    }

    private function text(string $text): ?string
    {
        return HtmlTextNormalizer::normalize(mb_convert_kana($text, 'as', 'UTF-8'));
    }

    private function nextDay(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }
}
