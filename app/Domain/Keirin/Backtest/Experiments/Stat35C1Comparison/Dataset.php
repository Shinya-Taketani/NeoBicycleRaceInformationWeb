<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\AgariC1Input\SourceProjector;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Generator;
use PDO;
use RuntimeException;

final class Dataset
{
    private array $released = [];

    public array $events = [];

    public function inspect(array $source): array
    {
        $report = [];
        foreach (InputContract::YEARS as $year) {
            $counts = ['races' => 0, 'entries' => 0, 'numeric' => 0, 'null' => 0, 'zero' => 0];
            $cohort = hash_init('sha256');
            foreach ($this->prediction($source, $year) as $race) {
                $counts['races']++;
                hash_update($cohort, Files::canonical(['year' => $year, 'race_id' => $race['race_id'],
                    'entries' => array_map(fn ($e) => [$e['id'], $e['bike']], $race['entries'])])."\n");
                foreach ($race['entries'] as $entry) {
                    $counts['entries']++;
                    $value = $entry['signals'][16];
                    $counts[$value === null ? 'null' : 'numeric']++;
                    $counts['zero'] += (int) ($value !== null && (float) $value === 0.0);
                }
            }
            $counts['ordered_cohort_sha256'] = hash_final($cohort);
            if (isset($source['input_summary'], $source['input_invariance'])) {
                foreach (['races', 'entries', 'numeric', 'null'] as $key) {
                    Files::same([$source['input_summary']['years'][$year][$key]], [$counts[$key]], 'fixed input count '.$year.' '.$key);
                }
                Files::same([$source['input_invariance'][$year]['ordered_cohort_sha256']], [$counts['ordered_cohort_sha256']], 'fixed input ordered cohort');
            }
            $report[$year] = $counts;
        }

        return $report;
    }

    public function prediction(array $source, int $year): Generator
    {
        $this->year($year);
        $paths = $source['paths'][$year];
        $sidecar = Artifacts::lines($paths['sidecar']);
        $original = Artifacts::lines($paths['original']);
        $sidecar->rewind();
        $original->rewind();
        $db = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-1024');
        $db->exec('CREATE TABLE seen (kind TEXT, id INTEGER, PRIMARY KEY(kind,id))');
        $seen = $db->prepare('INSERT INTO seen VALUES (?,?)');
        $db->beginTransaction();
        $count = $entries = $numeric = $null = 0;
        try {
            foreach (Artifacts::lines($paths['input']) as $race) {
                Validator::race($race, $year);
                $seen->execute(['race', $race['race_id']]);
                if (! $original->valid() || ! $sidecar->valid()) {
                    throw new RuntimeException('Original/sidecar omitted races.');
                }
                Files::same($race, SourceProjector::project($original->current(), $year, InputContract::C1_VERSION), 'fixed C1 non-result values/types/order');
                $extra = $sidecar->current();
                InputContract::keys($extra, ['year', 'race_id', 'entries']);
                self::cohort($race, $extra);
                foreach ($race['entries'] as $i => &$entry) {
                    $seen->execute(['entry', $entry['id']]);
                    $value = $extra['entries'][$i];
                    InputContract::keys($value, ['id', 'bike', 'stat35_mean6']);
                    $mean = $value['stat35_mean6'];
                    if ($mean !== null && ((! is_float($mean) && ! is_int($mean)) || ! is_finite($mean))) {
                        throw new RuntimeException('Invalid nullable STAT35 mean6.');
                    }
                    $numeric += (int) ($mean !== null);
                    $null += (int) ($mean === null);
                    $entries++;
                    $entry['signals'] = [...$entry['signals'], ...$entry['history'], $mean];
                    unset($entry['history'], $entry['history_status']);
                }
                unset($entry);
                $count++;
                yield $race;
                $sidecar->next();
                $original->next();
            }
            if ($sidecar->valid() || $original->valid() || $count !== $source['expected_rows'][$year] || $entries !== $source['expected_entries'][$year]) {
                throw new RuntimeException('Ordered input universe count disagreed.');
            }
            foreach (['input', 'sidecar', 'original'] as $key) {
                Files::verify($paths[$key], $source['seals'][$paths[$key]]);
            }
        } finally {
            $db->rollBack();
        }
    }

    public function training(array $source, array $years): Generator
    {
        foreach ($years as $year) {
            yield from $this->labelled($source, $year);
        }
    }

    public function labelled(array $source, int $year): Generator
    {
        $this->year($year);
        if ($year >= 2024 && ! isset($this->released[$year])) {
            throw new RuntimeException('Outer teacher access before both prediction seals.');
        }
        $this->events[] = ['event' => 'TEACHER_READ', 'year' => $year];
        $teacher = Artifacts::lines($source['paths'][$year]['teacher']);
        $teacher->rewind();
        foreach ($this->prediction($source, $year) as $race) {
            if (! $teacher->valid()) {
                throw new RuntimeException('Teacher omitted input race.');
            }
            $label = $teacher->current();
            InputContract::keys($label, ['year', 'race_id', 'entries']);
            self::cohort($race, $label);
            foreach ($label['entries'] as $i => $entry) {
                InputContract::keys($entry, [...InputContract::ENTRY_KEYS, ...($year <= 2023 ? ['labels'] : []), 'rank', 'status']);
                $expected = $race['entries'][$i];
                $signals = $expected['signals'];
                unset($expected['signals']);
                $projection = $entry;
                unset($projection['labels'], $projection['rank'], $projection['status'], $projection['signals'], $projection['history'], $projection['history_status']);
                Files::same($expected, $projection, 'teacher non-result fields');
                Files::same(array_slice($signals, 0, 16), [...$entry['signals'], ...$entry['history']], 'teacher fixed features');
                if (($entry['rank'] !== null && (! is_int($entry['rank']) || $entry['rank'] < 1 || $entry['rank'] > 9)) || ! is_string($entry['status'])) {
                    throw new RuntimeException('Invalid saved teacher.');
                }
                $race['entries'][$i]['rank'] = $entry['rank'];
                $race['entries'][$i]['status'] = $entry['status'];
            }
            yield $race;
            $teacher->next();
        }
        if ($teacher->valid()) {
            throw new RuntimeException('Teacher had extra races.');
        }
        $path = $source['paths'][$year]['teacher'];
        Files::verify($path, $source['seals'][$path]);
    }

    public function release(array $source, int $year, string $candidateDirectory): array
    {
        if (! in_array($year, [2024, 2025], true) || isset($this->released[$year])) {
            throw new RuntimeException('Invalid or repeated outer release.');
        }
        $model = $candidateDirectory.'/model.json';
        $predictions = $candidateDirectory.'/predictions.jsonl';
        $seal = Files::json($candidateDirectory.'/sealed.json');
        Files::verify($model, $seal['model']);
        Files::verify($predictions, $seal['predictions']);
        foreach ([$source['paths'][$year]['baseline'], $predictions] as $path) {
            $rows = JsonlArtifact::read($path);
            $rows->rewind();
            foreach ($this->prediction($source, $year) as $race) {
                if (! $rows->valid()) {
                    throw new RuntimeException('Prediction omitted input race.');
                }
                self::predictionCohort($race, $rows->current());
                $rows->next();
            }
            if ($rows->valid()) {
                throw new RuntimeException('Prediction had extra races.');
            }
        }
        $baseline = $source['paths'][$year]['baseline'];
        Files::verify($baseline, $source['seals'][$baseline]);
        Files::verify($model, $seal['model']);
        Files::verify($predictions, $seal['predictions']);
        $event = ['event' => 'OUTER_TEACHER_RELEASED', 'year' => $year, 'candidate_seal' => $seal, 'baseline_seal' => $source['seals'][$baseline]];
        $this->released[$year] = $event;
        $this->events[] = $event;

        return $event;
    }

    public function binned(callable $raw, Layout $layout, EffectBinBuilder $bins): Generator
    {
        foreach ($raw() as $race) {
            foreach ($race['entries'] as &$entry) {
                $entry['bins'] = $layout->assign($entry['signals'], $bins);
                unset($entry['signals']);
            }
            unset($entry);
            yield $race;
        }
    }

    public static function cohort(array $race, array $other): void
    {
        $identity = static fn ($r) => [$r['year'], $r['race_id'], array_map(static fn ($e) => [$e['id'], $e['bike']], $r['entries'])];
        Files::same($identity($race), $identity($other), 'ordered year/race/entry/bike');
    }

    public static function predictionCohort(array $race, array $prediction): void
    {
        self::cohort($race, $prediction['probabilities']);
        if ($prediction['decision']['year'] !== $race['year'] || $prediction['decision']['race_id'] !== $race['race_id']) {
            throw new RuntimeException('Prediction decision identity differed.');
        }
    }

    private function year(int $year): void
    {
        if (! in_array($year, InputContract::YEARS, true)) {
            throw new RuntimeException('Forbidden dataset year.');
        }
    }
}
