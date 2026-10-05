<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation;

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

    public function inspect(array $source, ?string $output = null): array
    {
        $report = [];
        $directory = $output === null ? null : Files::directory($output.'/derived-audit');
        foreach (InputContract::YEARS as $year) {
            $counts = ['races' => 0, 'entries' => 0, 'full_feature_count' => 16, 'retained_feature_count' => 15,
                'excluded_features' => Contract::EXCLUDED, 'stat10_null' => 0, 'stat10_zero' => 0,
                'stat10_types' => [], 'retained_values_types_order_verified' => true];
            $cohort = hash_init('sha256');
            $audit = (function () use ($source, $year, &$counts, $cohort): Generator {
                $pending = [];
                foreach ($this->prediction($source, $year, static function (array $row) use (&$pending): void {
                    $pending[] = $row;
                }) as $race) {
                    $counts['races']++;
                    hash_update($cohort, Files::canonical(['year' => $year, 'race_id' => $race['race_id'],
                        'entries' => array_map(fn ($e) => [$e['id'], $e['bike']], $race['entries'])])."\n");
                    foreach ($pending as $row) {
                        $counts['entries']++;
                        $value = $row['excluded_value'];
                        $counts['stat10_null'] += (int) ($value === null);
                        $counts['stat10_zero'] += (int) (($value === 0) || ($value === 0.0));
                        $type = get_debug_type($value);
                        $counts['stat10_types'][$type] = ($counts['stat10_types'][$type] ?? 0) + 1;
                        yield $row;
                    }
                    $pending = [];
                }
            })();
            if ($directory !== null) {
                JsonlArtifact::write($directory.'/projection-'.$year.'.jsonl', $audit);
            } else {
                foreach ($audit as $unused) {
                }
            }
            $counts['ordered_cohort_sha256'] = hash_final($cohort);
            $counts['c1_non_result_values_types_order_verified'] = true;
            $report[$year] = $counts;
        }

        return $report;
    }

    public function prediction(array $source, int $year, ?callable $audit = null): Generator
    {
        $this->year($year);
        $paths = $source['paths'][$year];
        $original = Artifacts::lines($paths['original']);
        $original->rewind();
        $db = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-1024');
        $db->exec('CREATE TABLE seen (kind TEXT, id INTEGER, PRIMARY KEY(kind,id))');
        $seen = $db->prepare('INSERT INTO seen VALUES (?,?)');
        $db->beginTransaction();
        $count = $entries = 0;
        try {
            foreach (Artifacts::lines($paths['input']) as $race) {
                Validator::race($race, $year);
                $seen->execute(['race', $race['race_id']]);
                if (! $original->valid()) {
                    throw new RuntimeException('Original omitted races.');
                }
                Files::same($race, SourceProjector::project($original->current(), $year, InputContract::C1_VERSION), 'fixed C1 non-result values/types/order');
                foreach ($race['entries'] as $entry) {
                    $seen->execute(['entry', $entry['id']]);
                    if ($audit !== null) {
                        $full = [...$entry['signals'], ...$entry['history']];
                        $mapping = Contract::projection()['full_to_retained_mapping']['STAT-10'];
                        $audit(['year' => $year, 'race_id' => $race['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike'],
                            'excluded_feature' => 'STAT-10', 'excluded_value' => $full[$mapping['full_index']],
                            'full_vector_sha256' => hash('sha256', Files::canonical($full)),
                            'retained_vector_sha256' => hash('sha256', Files::canonical(FeatureProjector::vector($full)))]);
                    }
                    $entries++;
                }
                $count++;
                yield FeatureProjector::race($race, $year);
                $original->next();
            }
            if ($original->valid() || $count !== $source['expected_rows'][$year] || $entries !== $source['expected_entries'][$year]) {
                throw new RuntimeException('Ordered input universe count disagreed.');
            }
            foreach (['input', 'original'] as $key) {
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
        $original = Artifacts::lines($source['paths'][$year]['original']);
        $original->rewind();
        foreach ($this->prediction($source, $year) as $race) {
            if (! $teacher->valid()) {
                throw new RuntimeException('Teacher omitted input race.');
            }
            $label = $teacher->current();
            InputContract::keys($label, ['year', 'race_id', 'entries']);
            self::cohort($race, $label);
            $full = $label;
            foreach ($full['entries'] as &$entry) {
                InputContract::keys($entry, [...InputContract::ENTRY_KEYS, ...($year <= 2023 ? ['labels'] : []), 'rank', 'status']);
                unset($entry['labels'], $entry['rank'], $entry['status']);
            }
            unset($entry);
            Validator::race($full, $year);
            Files::same(SourceProjector::project($original->current(), $year, InputContract::C1_VERSION), $full, 'teacher full fixed features before removal');
            Files::same($race, FeatureProjector::race($full, $year), 'teacher retained features');
            foreach ($label['entries'] as $i => $entry) {
                if (($entry['rank'] !== null && (! is_int($entry['rank']) || $entry['rank'] < 1 || $entry['rank'] > 9)) || ! is_string($entry['status'])) {
                    throw new RuntimeException('Invalid saved teacher.');
                }
                $race['entries'][$i]['rank'] = $entry['rank'];
                $race['entries'][$i]['status'] = $entry['status'];
            }
            yield $race;
            $teacher->next();
            $original->next();
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
