<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use PDO;
use RuntimeException;

final class Workspace
{
    public readonly PDO $db;

    public function __construct(string $path)
    {
        if (file_exists($path)) {
            throw new RuntimeException('New workspace required.');
        }
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA temp_store=FILE');
        $this->db->exec('PRAGMA cache_size=-4096');
        $this->db->exec('CREATE TABLE signals(id INTEGER PRIMARY KEY,race_id INTEGER,year INTEGER,raw REAL,data TEXT,used INTEGER DEFAULT 0);
            CREATE INDEX signals_race ON signals(race_id); CREATE INDEX scale_order ON signals(year,raw);
            CREATE TABLE metadata(id INTEGER PRIMARY KEY,data TEXT); CREATE TABLE seen(id INTEGER PRIMARY KEY);
            CREATE TABLE margins(id INTEGER PRIMARY KEY,year INTEGER,value REAL);');
    }

    public function load(string $signal, string $meeting): array
    {
        $q = $this->db->prepare('INSERT INTO signals(id,race_id,year,raw,data) VALUES(?,?,?,?,?)');
        $counts = array_fill_keys(Contract::YEARS, 0);
        $states = $ambiguous = array_fill_keys(Contract::YEARS, []);
        $this->db->beginTransaction();
        foreach (JsonlArtifact::read($signal) as $row) {
            Signal::validate($row);
            $q->execute([$row['entry_id'], $row['race_id'], $row['year'], $row['raw'], Files::canonical($row)]);
            $counts[$row['year']]++;
            $states[$row['year']][$row['status']] = ($states[$row['year']][$row['status']] ?? 0) + 1;
            if ($row['boundary_ambiguous']) {
                $ambiguous[$row['year']][] = $row['entry_id'];
            }
        }
        $meetings = Files::json($meeting.'/meetings.json');
        $q = $this->db->prepare('INSERT INTO metadata VALUES(?,?)');
        foreach (JsonlArtifact::read($meeting.'/metadata.jsonl') as $row) {
            Classification::validate($row);
            $m = $meetings[$row['meeting_id'] ?? 'missing:'.$row['race_id']] ?? throw new RuntimeException('Missing fixed meeting.');
            $meta = ['year' => $row['year'], 'race_id' => $row['race_id'], 'grade' => $m['grade'],
                'class' => Classification::raceClass($row['race_type_raw']), 'entries' => $row['entrant_count']];
            $q->execute([$row['race_id'], Files::canonical($meta)]);
        }
        $this->db->commit();

        return ['entries' => $counts, 'states' => $states, 'boundary_ambiguous_entry_ids' => $ambiguous];
    }

    public function join(array $race, float $margin): array
    {
        Contract::year($race['year']);
        $this->db->prepare('INSERT INTO seen VALUES(?)')->execute([$race['race_id']]);
        $this->db->prepare('INSERT INTO margins VALUES(?,?,?)')->execute([$race['race_id'], $race['year'], sprintf('%.17g', $margin)]);
        $q = $this->db->prepare('SELECT data FROM metadata WHERE id=?');
        $q->execute([$race['race_id']]);
        $meta = json_decode($q->fetchColumn() ?: 'null', true, flags: JSON_THROW_ON_ERROR);
        $q = $this->db->prepare('SELECT id,data FROM signals WHERE race_id=?');
        $q->execute([$race['race_id']]);
        $signals = [];
        foreach ($q as $row) {
            $signals[$row['id']] = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
        }
        if (($meta['year'] ?? null) !== $race['year'] || $meta['entries'] !== count($race['entries']) || count($signals) !== count($race['entries'])) {
            throw new RuntimeException('Signal/input/metadata universe mismatch.');
        }
        $aligned = [];
        $mark = $this->db->prepare('UPDATE signals SET used=1 WHERE id=? AND used=0');
        foreach ($race['entries'] as $entry) {
            $g = $signals[$entry['id']] ?? throw new RuntimeException('Missing entry signal.');
            if ($g['year'] !== $race['year'] || $g['bike'] !== $entry['bike']) {
                throw new RuntimeException('Signal identity mismatch.');
            }
            $mark->execute([$entry['id']]);
            if ($mark->rowCount() !== 1) {
                throw new RuntimeException('Duplicate entry.');
            }
            $aligned[] = $g;
        }

        return ['race' => $race, 'growth' => $aligned, 'metadata' => $meta, 'p1_margin' => $margin];
    }

    public function complete(): void
    {
        if ((int) $this->db->query('SELECT count(*) FROM signals WHERE used=0')->fetchColumn() !== 0
            || $this->db->query('SELECT count(*) FROM metadata')->fetchColumn() !== $this->db->query('SELECT count(*) FROM seen')->fetchColumn()) {
            throw new RuntimeException('Incomplete prediction cohort.');
        }
    }

    public function scaling(): array
    {
        $n = (int) $this->db->query('SELECT count(raw) FROM signals WHERE year=2024')->fetchColumn();
        $scale = $this->quantile('SELECT abs(raw) AS v FROM signals WHERE year=2024 AND raw IS NOT NULL ORDER BY v', $n, 0.99);
        if ($scale === null || ! is_finite($scale) || $scale <= 0) {
            throw new RuntimeException('SCALE_P99 must be positive finite.');
        }

        return ['year' => 2024, 'signal_id' => Contract::SIGNAL, 'valid_n' => $n, 'abs_raw_p99' => $scale,
            'type7' => 'h=(n-1)*0.99; x[floor(h)]+(x[ceil(h)]-x[floor(h)])*(h-floor(h))',
            'clip_min' => -1, 'clip_max' => 1, 'formula' => 'clamp(raw / abs_raw_p99,-1,1); invalid=NULL'];
    }

    public function marginCuts(): array
    {
        $cuts = [];
        foreach (Contract::YEARS as $year) {
            $n = (int) $this->db->query('SELECT count(*) FROM margins WHERE year='.$year)->fetchColumn();
            $cuts[$year] = array_map(fn ($p) => $this->quantile('SELECT value FROM margins WHERE year='.$year.' ORDER BY value', $n, $p), [0.25, 0.5, 0.75]);
        }

        return $cuts;
    }

    private function quantile(string $sql, int $n, float $p): ?float
    {
        if ($n === 0) {
            return null;
        }
        $h = ($n - 1) * $p;
        $v = $this->db->query($sql.' LIMIT 2 OFFSET '.(int) floor($h))->fetchAll(PDO::FETCH_COLUMN);

        return (float) $v[0] + ((float) ($v[1] ?? $v[0]) - (float) $v[0]) * ($h - floor($h));
    }
}
