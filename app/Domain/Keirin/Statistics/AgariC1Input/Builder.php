<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Input;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use RuntimeException;

final class Builder
{
    public function __construct(private readonly Sources $sources) {}

    public function build(string $c1, string $history, string $output, ?string $context = null, ?string $reproduce = null): array
    {
        $code = Contract::code();
        $source = $this->sources->open($c1, $history, $context);
        // No output directory (including a new child of a fixed source) may be created there.
        $parent = realpath(dirname($output));
        if ($parent === false) {
            throw new RuntimeException('Output parent must already exist.');
        }
        foreach (array_filter([$c1, $history, $context, $reproduce]) as $root) {
            $root = realpath($root);
            if ($root !== false && ($parent === $root || str_starts_with($parent.'/', $root.'/'))) {
                throw new RuntimeException('Output overlaps a source.');
            }
        }
        $previous = $reproduce === null ? null : self::published($reproduce);
        Artifacts::create($output);
        try {
            $index = new Index($output.'/workspace.sqlite');
            $index->ingest($source);
            $files = $years = $invariance = [];
            $raceInsert = $index->db->prepare('INSERT INTO races VALUES (?)');
            $entryInsert = $index->db->prepare('INSERT INTO entries VALUES (?)');
            foreach (Contract::YEARS as $year) {
                $input = new Stream($output.'/c1-'.$year.'.jsonl');
                $sidecar = new Stream($output.'/stat35-'.$year.'.jsonl');
                $audit = new Stream($output.'/audit-'.$year.'.jsonl');
                $counts = ['races' => 0, 'entries' => 0, 'connected' => 0, 'unmatched' => 0, 'duplicate_context' => 0,
                    'conflicting_context' => 0, 'numeric' => 0, 'null' => 0, 'null_reasons' => [], 'flags' => []];
                $cohort = hash_init('sha256');
                $nonResult = hash_init('sha256');
                $index->db->beginTransaction();
                foreach (Artifacts::lines($c1.'/inputs-'.$year.'.jsonl') as $raw) {
                    $race = SourceProjector::project($raw, $year, Contract::C1_VERSION);
                    $raceInsert->execute([$race['race_id']]);
                    $input->row($race);
                    hash_update($nonResult, Files::canonical($race)."\n");
                    $contexts = $external = $raceMeetings = $raceClasses = [];
                    foreach ($race['entries'] as $entry) {
                        $entryInsert->execute([$entry['id']]);
                        $ctx = $index->context($race, $entry);
                        $contexts[] = $ctx;
                        if ($ctx['validity']['meeting']) {
                            $m = $ctx['evidence']['meeting'];
                            $raceMeetings[Files::canonical([$m['meeting_id'], $m['starts_on'], $m['ends_on'], $ctx['evidence']['race_date']])] = true;
                        }
                        if ($ctx['validity']['class']) {
                            $raceClasses[$ctx['class']] = true;
                        }
                        $id = $ctx['evidence']['external_player_id'] ?? null;
                        if ($ctx['validity']['identity']) {
                            $external[$id] = ($external[$id] ?? 0) + 1;
                        }
                    }
                    $extra = [];
                    foreach ($race['entries'] as $i => $entry) {
                        $ctx = $contexts[$i];
                        if (count($raceMeetings) > 1 || count($raceClasses) > 1) {
                            $ctx['reasons'][] = 'CONFLICTING_RACE_CONTEXT';
                        }
                        if ($ctx['validity']['identity'] && ($external[$ctx['evidence']['external_player_id']] ?? 0) > 1) {
                            $ctx['reasons'][] = 'CONTEXT_IDENTITY_CONFLICT';
                        }
                        $ctx['reasons'] = array_values(array_unique($ctx['reasons']));
                        $result = $index->calculate($ctx);
                        $item = ['id' => $entry['id'], 'bike' => $entry['bike'], 'stat35_mean6' => $result['value']];
                        $extra[] = $item;
                        $counts['entries']++;
                        $counts[$ctx['reasons'] === [] ? 'connected' : 'unmatched']++;
                        $counts['duplicate_context'] += (int) in_array('DUPLICATE_CONTEXT', $ctx['reasons'], true);
                        $counts['conflicting_context'] += (int) (array_intersect(['CONTEXT_IDENTITY_CONFLICT', 'CONFLICTING_RACE_CONTEXT'], $ctx['reasons']) !== []);
                        $counts[$result['value'] === null ? 'null' : 'numeric']++;
                        foreach ($result['reasons'] as $reason) {
                            $counts['null_reasons'][$reason] = ($counts['null_reasons'][$reason] ?? 0) + 1;
                        }
                        foreach ($result['window']['flags'] as $flag => $yes) {
                            $counts['flags'][$flag] = ($counts['flags'][$flag] ?? 0) + (int) $yes;
                        }
                        $audit->row(['year' => $year, 'race_id' => $race['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike'],
                            'context' => $ctx, 'context_source' => $context === null ? null : [
                                'path' => $context.'/entry-context.jsonl', 'seal' => $source['seals'][$context.'/entry-context.jsonl'],
                                'fields' => ['external_player_id', 'meeting', 'race_type', 'observed_at', 'source_record_id']],
                            'target_source' => ['path' => $c1.'/history-'.$year.'.jsonl',
                                'seal' => $source['seals'][$c1.'/history-'.$year.'.jsonl'], 'fields' => array_keys($ctx['target'])],
                            'timing' => 'HISTORICAL_PUBLICATION_NOT_GUARANTEED', 'reasons' => $result['reasons'],
                            'window' => $result['window'], 'float' => $result['value']]);
                    }
                    $sidecar->row(['year' => $year, 'race_id' => $race['race_id'], 'entries' => $extra]);
                    hash_update($cohort, Files::canonical(['year' => $year, 'race_id' => $race['race_id'],
                        'entries' => array_map(fn ($e) => [$e['id'], $e['bike']], $race['entries'])])."\n");
                    $counts['races']++;
                }
                $index->db->commit();
                if ($counts['races'] !== $source['expected_rows'][$year]) {
                    throw new RuntimeException('C1 manifest row count mismatch.');
                }
                if ($counts['entries'] !== $source['expected_targets'][$year]) {
                    throw new RuntimeException('C1 target/input entry count mismatch.');
                }
                $files['c1-'.$year.'.jsonl'] = $input->finish();
                $files['stat35-'.$year.'.jsonl'] = $sidecar->finish();
                $files['audit-'.$year.'.jsonl'] = $audit->finish();
                $hash = hash_final($nonResult);
                if ($hash !== $files['c1-'.$year.'.jsonl']['sha256']) {
                    throw new RuntimeException('Projected C1 changed during output.');
                }
                $invariance[$year] = ['ordered_cohort_sha256' => hash_final($cohort), 'non_result_sha256' => $hash,
                    'semantic_input_sha256' => hash('sha256', $hash.$files['stat35-'.$year.'.jsonl']['sha256']),
                    'projection_exact' => true];
                ksort($counts['null_reasons']);
                ksort($counts['flags']);
                $years[$year] = $counts;
            }
            $totals = [];
            foreach (['races', 'entries', 'connected', 'unmatched', 'duplicate_context', 'conflicting_context', 'numeric', 'null'] as $key) {
                $totals[$key] = array_sum(array_column($years, $key));
            }
            $status = $totals['entries'] === 0 ? 'EMPTY_INPUT' : ($totals['numeric'] === 0 ? 'DIAGNOSTIC_ALL_NULL' : 'INPUTS_PREPARED');
            $summary = ['status' => $status, 'years' => $years, 'totals' => $totals,
                'context_evidence_available' => $context !== null, 'reason_counts_nonexclusive' => true];
            $files['summary.json'] = Artifacts::json($output, 'summary.json', $summary);
            $files['invariance.json'] = Artifacts::json($output, 'invariance.json', $invariance);
            Sources::verify($source);
            Files::same($code, Contract::code(), 'processing code start/end');
            $manifest = ['contract' => Contract::plan(), 'status' => $status, 'source' => $source, 'code' => $code, 'files' => $files];
            if ($previous !== null) {
                Files::same($previous, $manifest, 'independent reproduction');
                Files::same($previous, self::published($reproduce), 'original reproduction bundle start/end');
                Artifacts::json($output, 'reproduction.json', ['identical' => true, 'original' => $reproduce, 'files' => array_keys($files)]);
            }
            foreach ($files as $name => $seal) {
                Files::verify($output.'/'.$name, $seal);
            }
            $seal = Artifacts::json($output, 'manifest.json', $manifest);
            Artifacts::json($output, $status === 'INPUTS_PREPARED' ? 'COMPLETE.json' : 'DIAGNOSTIC.json', $seal);

            return $summary;
        } catch (\Throwable $e) {
            Artifacts::json($output, 'FAILED.json', ['status' => 'FAILED', 'error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function published(string $dir): array
    {
        $marker = is_file($dir.'/COMPLETE.json') ? 'COMPLETE.json' : 'DIAGNOSTIC.json';
        Files::verify($dir.'/manifest.json', Files::json($dir.'/'.$marker));
        $manifest = Files::json($dir.'/manifest.json');
        Files::same(Contract::plan(), $manifest['contract'], 'input artifact contract');
        foreach ($manifest['files'] as $name => $seal) {
            if (basename($name) !== $name) {
                throw new RuntimeException('Unsafe output manifest path.');
            }
            Files::verify($dir.'/'.$name, $seal);
        }

        return $manifest;
    }
}
