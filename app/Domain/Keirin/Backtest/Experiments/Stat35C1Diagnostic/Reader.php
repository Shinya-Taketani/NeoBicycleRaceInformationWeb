<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as Input;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Generator;
use PDO;
use RuntimeException;

final class Reader
{
    public function rows(array $source, int $year, ?PDO $identities = null): Generator
    {
        if (! in_array($year, Contract::YEARS, true)) {
            throw new RuntimeException('Forbidden diagnostic year.');
        }
        $paths = $source['paths'][$year];
        $streams = [];
        foreach (['sidecar', 'c1_prediction', 'c2_prediction', 'teacher', 'contributions'] as $key) {
            $streams[$key] = Artifacts::lines($paths[$key]);
            $streams[$key]->rewind();
        }
        $db = $identities ?? self::identityIndex();
        $insert = $db->prepare('INSERT INTO seen VALUES (?,?)');
        $races = $entries = 0;
        $cohort = hash_init('sha256');
        try {
            foreach (Artifacts::lines($paths['input']) as $race) {
                Validator::race($race, $year);
                $insert->execute(['race', $race['race_id']]);
                $rows = [];
                foreach ($streams as $key => $stream) {
                    if (! $stream->valid()) {
                        throw new RuntimeException('Incomplete diagnostic stream: '.$key);
                    }
                    $rows[$key] = $stream->current();
                }
                foreach (['sidecar', 'teacher'] as $key) {
                    Input::keys($rows[$key], ['year', 'race_id', 'entries']);
                    self::cohort($race, $rows[$key]);
                }
                foreach (['c1_prediction', 'c2_prediction'] as $key) {
                    self::cohort($race, $rows[$key]['probabilities']);
                    $this->decision($race, $rows[$key]['decision']);
                }
                if (($rows['contributions']['race_id'] ?? null) !== $race['race_id']) {
                    throw new RuntimeException('Saved contribution race order differed.');
                }
                $means = $labels = [];
                foreach ($race['entries'] as $i => $entry) {
                    $insert->execute(['entry', $entry['id']]);
                    $extra = $rows['sidecar']['entries'][$i];
                    Input::keys($extra, ['id', 'bike', 'stat35_mean6']);
                    $mean = $extra['stat35_mean6'];
                    if ($mean !== null && ((! is_float($mean) && ! is_int($mean)) || ! is_finite($mean) || $mean < 0 || $mean > 1)) {
                        throw new RuntimeException('Invalid mean6 value.');
                    }
                    $means[] = $mean;
                    $teacher = $rows['teacher']['entries'][$i];
                    Input::keys($teacher, ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'signals', 'rank', 'status']);
                    $expected = $entry;
                    $expected['signals'] = [...$entry['signals'], ...$entry['history'], $mean];
                    unset($expected['history'], $expected['history_status']);
                    $projection = $teacher;
                    unset($projection['rank'], $projection['status']);
                    Files::same($expected, $projection, 'teacher non-outcome fields versus fixed INPUT');
                    if (($teacher['rank'] !== null && (! is_int($teacher['rank']) || $teacher['rank'] < 1 || $teacher['rank'] > 9))
                        || ! is_string($teacher['status'])) {
                        throw new RuntimeException('Invalid diagnostic teacher label.');
                    }
                    $labels[] = ['id' => $entry['id'], 'bike' => $entry['bike'], 'raw' => $entry['raw'],
                        'rank' => $teacher['rank'], 'status' => $teacher['status']];
                }
                $races++;
                $entries += count($race['entries']);
                hash_update($cohort, Files::canonical(['year' => $year, 'race_id' => $race['race_id'],
                    'entries' => array_map(fn ($e) => [$e['id'], $e['bike']], $race['entries'])])."\n");
                yield ['input' => $race, 'means' => $means,
                    'labels' => ['year' => $year, 'race_id' => $race['race_id'], 'entries' => $labels],
                    'c1' => $rows['c1_prediction'], 'c2' => $rows['c2_prediction'], 'contributions' => $rows['contributions']];
                foreach ($streams as $stream) {
                    $stream->next();
                }
            }
            foreach ($streams as $key => $stream) {
                if ($stream->valid()) {
                    throw new RuntimeException('Extra diagnostic stream rows: '.$key);
                }
            }
            Files::same($source['expected'][$year], ['races' => $races, 'entries' => $entries], 'diagnostic cohort counts');
            $hash = hash_final($cohort);
            if (isset($source['ordered_cohorts'][$year])) {
                Files::same([$source['ordered_cohorts'][$year]['ordered_cohort_sha256']], [$hash], 'fixed input order hash');
            }
        } finally {
            if ($identities === null) {
                $db->rollBack();
            }
        }
    }

    public static function identityIndex(): PDO
    {
        // Disk-backed identity set, shared across years by Builder. No ID ordering assumption.
        $db = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-1024');
        $db->exec('PRAGMA temp_store=FILE');
        $db->exec('CREATE TABLE seen (kind TEXT, id INTEGER, PRIMARY KEY (kind,id))');
        $db->beginTransaction();

        return $db;
    }

    public static function cohort(array $race, array $other): void
    {
        $id = static fn ($r) => [$r['year'], $r['race_id'], array_map(static fn ($e) => [$e['id'], $e['bike']], $r['entries'])];
        Files::same($id($race), $id($other), 'ordered year/race/entry/bike');
    }

    private function decision(array $race, array $decision): void
    {
        if (($decision['year'] ?? null) !== $race['year'] || ($decision['race_id'] ?? null) !== $race['race_id']) {
            throw new RuntimeException('Decision identity differed.');
        }
        $primary = [];
        foreach ([1, 2, 3] as $k) {
            $bike = $decision['primary_position_'.$k.'_bike'] ?? null;
            if (! is_int($bike) || ! in_array($bike, array_column($race['entries'], 'bike'), true) || in_array($bike, $primary, true)) {
                throw new RuntimeException('Invalid saved Primary decision.');
            }
            $primary[] = $bike;
        }
    }
}
