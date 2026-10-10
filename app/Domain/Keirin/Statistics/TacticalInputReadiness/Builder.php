<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\TacticalInputReadiness;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use PDO;
use RuntimeException;

final class Builder
{
    public function build(string $snapshot, string $output, ?string $original = null): array
    {
        $manifest = Contract::published($snapshot, 'AUDIT');
        $sourceSeal = Files::identity($snapshot.'/manifest.json');
        $code = Contract::code();
        $reference = $original === null ? null : Contract::published($original, 'CANDIDATE');
        Contract::output($output, [$snapshot, ...($original === null ? [] : [$original])]);
        try {
            $db = Universe::index($output.'/join.sqlite');
            $put = $db->prepare('INSERT INTO entries(id,body) VALUES (?,?)');
            $get = $db->prepare('SELECT body,used FROM entries WHERE id=?');
            $use = $db->prepare('UPDATE entries SET used=1 WHERE id=?');
            $racePut = $db->prepare('INSERT INTO races(id) VALUES (?)');
            $db->beginTransaction();
            foreach (Artifacts::lines($snapshot.'/records.jsonl') as $row) {
                C1::keys($row, ['target_entry_id', 'record']);
                C1::keys($row['record'], Contract::RECORD_KEYS);
                if (! C1::id($row['target_entry_id'])) {
                    throw new RuntimeException('Invalid observation identity.');
                }
                $put->execute([$row['target_entry_id'], Files::canonical($row['record'])]);
            }
            $streams = $years = $inventory = [];
            foreach (Contract::YEARS as $year) {
                $streams[$year] = new Stream($output.'/tactical-input-'.$year.'.jsonl');
                $years[$year] = ['races' => 0, 'entries' => 0, 'matched' => 0, 'mismatched' => 0,
                    'style_values' => 0, 'line_values' => 0, 'normalizable' => 0, 'timing_verified' => 0, 'timing_unknown' => 0,
                    'style_states' => [], 'line_states' => []];
                $inventory[$year] = [];
            }
            foreach (Artifacts::lines($snapshot.'/targets.jsonl') as $race) {
                C1::keys($race, ['year', 'race_id', 'entries']);
                Universe::race($race);
                $year = $race['year'];
                $racePut->execute([$race['race_id']]);
                $entries = [];
                foreach ($race['entries'] as $t) {
                    C1::keys($t, [...\App\Domain\Keirin\Statistics\AgariC1Context\Contract::TARGET_FIELDS, 'external_player_id']);
                    Contract::target(array_diff_key($t, ['external_player_id' => true]), $year);
                    if ($t['race_id'] !== $race['race_id']) {
                        throw new RuntimeException('Target race mismatch.');
                    }
                    $get->execute([$t['entry_id']]);
                    $r = $get->fetch(PDO::FETCH_ASSOC);
                    $get->closeCursor();
                    if (! $r || $r['used'] !== 0) {
                        throw new RuntimeException('Missing/duplicate target observation.');
                    }
                    $use->execute([$t['entry_id']]);
                    $entry = Attributes::classify($t, json_decode($r['body'], true, flags: JSON_THROW_ON_ERROR));
                    $entries[] = $entry;
                    $y = &$years[$year];
                    $y['entries']++;
                    $y[$entry['identity'] === 'MATCH' ? 'matched' : 'mismatched']++;
                    foreach (['riding_style' => 'style', 'line' => 'line'] as $field => $short) {
                        $state = $entry[$field]['value_status'];
                        $y[$short.'_states'][$state] = ($y[$short.'_states'][$state] ?? 0) + 1;
                        $y[$short.'_values'] += (int) ($state === 'VALUE');
                    }
                    $y['normalizable'] += (int) ($entry['riding_style']['normalized'] !== null);
                    $y['timing_unknown']++;
                    $key = Files::canonical(['raw' => $entry['riding_style']['raw']]);
                    $inventory[$year][$key] = ($inventory[$year][$key] ?? 0) + 1;
                    unset($y);
                }
                $streams[$year]->row(['year' => $year, 'race_id' => $race['race_id'], 'race_date' => $race['entries'][0]['race_date'], 'entries' => $entries]);
                $years[$year]['races']++;
            }
            if ((int) $db->query('SELECT COUNT(*) FROM entries WHERE used=0')->fetchColumn() !== 0) {
                throw new RuntimeException('Extra observation outside fixed cohort.');
            }
            foreach (Contract::YEARS as $year) {
                if ($years[$year]['races'] !== $manifest['years'][$year]['races'] || $years[$year]['entries'] !== $manifest['years'][$year]['entries']
                    || $years[$year]['mismatched'] !== 0) {
                    throw new RuntimeException('Incomplete or mismatched fixed cohort; COMPLETE forbidden.');
                }
                ksort($years[$year]['style_states']);
                ksort($years[$year]['line_states']);
                ksort($inventory[$year]);
            }
            $db->commit();
            $files = ['contract.json' => Artifacts::json($output, 'contract.json', Contract::plan()),
                'coverage.json' => Artifacts::json($output, 'coverage.json', ['conclusion' => 'PARTIAL_READINESS', 'years' => $years,
                    'historical_as_of_available' => false, 'accuracy_improvement' => 'NOT_MEASURED']),
                'category-inventory.json' => Artifacts::json($output, 'category-inventory.json', ['styles' => $inventory, 'normalization' => Contract::STYLES])];
            foreach ($streams as $year => $stream) {
                $files['tactical-input-'.$year.'.jsonl'] = $stream->finish();
            }
            if ($reference !== null) {
                Files::same($reference['files'], $files, 'offline reproduction files');
                Files::same($reference['source_manifest'], $sourceSeal, 'reproduction snapshot');
                Contract::published($original, 'CANDIDATE');
            }
            Contract::published($snapshot, 'AUDIT');
            Files::verify($snapshot.'/manifest.json', $sourceSeal);
            Files::same($code, Contract::code(), 'candidate code START/END');
            Artifacts::publish($output, ['version' => Contract::VERSION, 'kind' => 'CANDIDATE', 'historical_as_of_available' => false,
                'prediction_use' => 'NOT_AUTHORIZED', 'source_manifest' => $sourceSeal, 'files' => $files, 'code' => $code]);

            return ['status' => 'PARTIAL_READINESS', 'years' => $years, 'reproduction' => $reference === null ? 'NOT_RUN' : 'IDENTICAL'];
        } catch (\Throwable $e) {
            Artifacts::json($output, 'FAILED.json', ['error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }
}
