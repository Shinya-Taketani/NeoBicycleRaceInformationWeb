<?php

declare(strict_types=1);

namespace App\Domain\Keirin\TrackContext;

use DateTimeImmutable;
use RuntimeException;

/** Sealed structural-column extracts and visually checked official programme transcripts. */
final class HistoricalStructureExcerptParser
{
    public const ANNUAL_COLUMNS = ['bank_circumference_m' => '周長', 'cant' => '最大カント', 'straight_slope' => '直線部カント', 'straight_length_m' => '見なし直線距離'];

    public const PROGRAM_COLUMNS = ['bank_circumference_m' => '周長', 'straight_length_m' => 'みなし直線', 'cant' => '最大カント'];

    public function parse(string $bytes, array $evidence): array
    {
        return match ($evidence['format'] ?? null) {
            'ANNUAL_2023_STRUCTURAL_COLUMNS_TSV' => $this->annual($bytes, $evidence),
            'MAEBASHI_2023_PROGRAM_TRANSCRIPT', 'HIRATSUKA_2024_PROGRAM_TRANSCRIPT' => $this->program($bytes, $evidence),
            default => throw new RuntimeException('Unsupported historical excerpt format.'),
        };
    }

    private function annual(string $bytes, array $evidence): array
    {
        $lines = explode("\n", rtrim($bytes, "\n"));
        if (array_shift($lines) !== '2023年版 競輪年間記録集'
            || array_shift($lines) !== '■バンクレコード'
            || array_shift($lines) !== "競輪場\t周長\t最大カント\t直線部カント\t見なし直線距離"
            || ($evidence['columns'] ?? null) !== self::ANNUAL_COLUMNS) {
            throw new RuntimeException('Invalid annual structural table heading/columns.');
        }
        $rows = [];
        foreach ($lines as $line) {
            $cells = explode("\t", $line);
            if (count($cells) !== 5 || $cells[0] === '' || isset($rows[$cells[0]])) {
                throw new RuntimeException('Invalid/duplicate annual structural row.');
            }
            $fields = [];
            foreach (array_keys(self::ANNUAL_COLUMNS) as $index => $key) {
                $fields[$key] = $this->field($key, $cells[$index + 1], $key !== 'bank_circumference_m');
            }
            $rows[$cells[0]] = $fields;
        }
        $fields = $rows[$evidence['row'] ?? ''] ?? throw new RuntimeException('Annual track row missing.');

        // The publication year and record dates do NOT establish structural applicability.
        return [...$evidence, 'fields' => $fields, 'period' => null];
    }

    private function program(string $bytes, array $evidence): array
    {
        $lines = explode("\n", rtrim($bytes, "\n"));
        $maebashi = $evidence['format'] === 'MAEBASHI_2023_PROGRAM_TRANSCRIPT';
        $id = $maebashi ? '22' : '35';
        $name = $maebashi ? '前橋' : '平塚';
        $columns = $maebashi
            ? ['bank_circumference_m' => 'バンク周長', 'straight_length_m' => 'ゴールまでの見なし直線', 'cant' => '最大カント']
            : self::PROGRAM_COLUMNS;
        if (($evidence['track_id'] ?? null) !== $id || ($evidence['row'] ?? null) !== $name
            || ($evidence['columns'] ?? null) !== $columns) {
            throw new RuntimeException('Programme identity/columns mismatch.');
        }
        if ($maebashi) {
            if (array_shift($lines) !== '前橋競輪開設73周年記念 三山王冠争奪戦 GⅢ'
                || ! preg_match('/\A([0-9]{4})\.([0-9]{1,2})\.([0-9]{1,2})THU\/([0-9]{1,2})FRI\/([0-9]{1,2})\.([0-9]{1,2})SAT\/([0-9]{1,2})SUN\z/', array_shift($lines) ?? '', $m)
                || array_shift($lines) !== '前橋競輪場 - バンクの特徴 -'
                || ($evidence['event_index'] ?? null) !== 0) {
                throw new RuntimeException('Invalid Maebashi programme/date evidence.');
            }
            $days = [$this->day($m[1], $m[2], $m[3]), $this->day($m[1], $m[2], $m[4]), $this->day($m[1], $m[5], $m[6]), $this->day($m[1], $m[5], $m[7])];
            $weekdays = ['Thu', 'Fri', 'Sat', 'Sun'];
        } else {
            if (array_shift($lines) !== '湘南ダービー×HPCJC リンカイ！平塚ナナ杯'
                || ! preg_match('/\A([0-9]{4}) ([0-9]{1,2}) ([0-9]{1,2})MON ([0-9]{1,2})TUE ([0-9]{1,2})WED\z/', array_shift($lines) ?? '', $a)
                || array_shift($lines) !== '湘南ダービー×HPCJC サテライト横浜カップ'
                || ! preg_match('/\A([0-9]{4}) ([0-9]{1,2}) ([0-9]{1,2})FRI ([0-9]{1,2})SAT ([0-9]{1,2})SUN ([0-9]{1,2})MON\z/', array_shift($lines) ?? '', $b)
                || array_shift($lines) !== '平塚競輪場 バンクデータ'
                || ! in_array($evidence['event_index'] ?? null, [0, 1], true)) {
                throw new RuntimeException('Invalid Hiratsuka programme/date evidence.');
            }
            $periods = [];
            foreach ([[$a, ['Mon', 'Tue', 'Wed']], [$b, ['Fri', 'Sat', 'Sun', 'Mon']]] as [$m, $week]) {
                $dates = array_map(fn (string $d): string => $this->day($m[1], $m[2], $d), array_slice($m, 3));
                $periods[] = $this->period($dates, $week);
            }
            $period = $periods[$evidence['event_index']];
        }
        $fields = [];
        foreach ($lines as $line) {
            $cells = explode("\t", $line);
            $key = count($cells) === 2 ? array_search($cells[0], $columns, true) : false;
            if ($key === false || isset($fields[$key])) {
                throw new RuntimeException('Invalid/duplicate programme structural field.');
            }
            $fields[$key] = $this->field($key, $cells[1], true);
        }
        if (count($fields) !== count($columns)) {
            throw new RuntimeException('Programme structural field missing.');
        }

        return [...$evidence, 'fields' => $fields, 'period' => $maebashi ? $this->period($days, $weekdays) : $period];
    }

    private function day(string $year, string $month, string $day): string
    {
        return StructureValues::date(sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day));
    }

    private function period(array $days, array $weekdays): array
    {
        $next = null;
        foreach ($days as $i => $day) {
            $date = new DateTimeImmutable($day);
            if (($next !== null && $next !== $day) || $date->format('D') !== $weekdays[$i]) {
                throw new RuntimeException('Nonconsecutive or inconsistent programme days.');
            }
            $next = $date->modify('+1 day')->format('Y-m-d');
        }

        return [$days[0], $next];
    }

    private function field(string $key, string $raw, bool $requireMetres): array
    {
        $unit = TrackContextMaster::FIELDS[$key];
        if ($unit === 'DMS') {
            $value = StructureValues::dms($raw);
        } else {
            $pattern = $requireMetres ? '/\A([0-9]+(?:\.[0-9]+)?)[mｍ]\z/u' : '/\A([0-9]+(?:\.[0-9]+)?)\z/';
            if (! preg_match($pattern, $raw, $m)) {
                throw new RuntimeException('Invalid historical structural decimal/unit.');
            }
            StructureValues::positiveDecimal($m[1]);
            $value = $m[1];
        }

        return ['raw' => $raw, 'value' => $value, 'unit' => $unit];
    }
}
