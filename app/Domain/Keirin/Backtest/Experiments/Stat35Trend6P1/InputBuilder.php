<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use PDO;
use RuntimeException;

final class InputBuilder
{
    public function build(array $source, string $directory): array
    {
        Sources::verify($source);
        if (file_exists($directory.'/history-index.sqlite') || is_link($directory.'/history-index.sqlite')) {
            throw new RuntimeException('History workspace must be new.');
        }
        $db = new PDO('sqlite:'.$directory.'/history-index.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-2048; PRAGMA temp_store=FILE;
            CREATE TABLE history(id TEXT PRIMARY KEY, external TEXT, class TEXT, ends TEXT, starts TEXT, meeting INTEGER, body TEXT);
            CREATE INDEX past ON history(external,class,ends,starts);
            CREATE UNIQUE INDEX series_meeting ON history(external,class,meeting);');
        $put = $db->prepare('INSERT INTO history VALUES (?,?,?,?,?,?,?)');
        $db->beginTransaction();
        foreach (Artifacts::lines($source['history'].'/player-meetings.jsonl') as $row) {
            if (! InputContract::date($row['first_observed_date']) || $row['first_observed_date'] < '2022-01-01' || $row['first_observed_date'] > '2025-12-31') {
                throw new RuntimeException('Unauthorized history event year.');
            }
            if ($row['history_context_eligible'] !== true) {
                continue;
            }
            $m = $row['meeting'];
            $body = array_intersect_key($row, array_flip(['id', 'source', 'external_player_id', 'measurement_definition_id', 'race_class',
                'history_context_eligible', 'meeting', 'meeting_percentile_mean', 'context_flags', 'adopted_races', 'exclusion_reasons']));
            $put->execute([$row['id'], $row['external_player_id'], $row['race_class'], $m['ends_on'], $m['starts_on'], $m['meeting_id'], Files::canonical($body)]);
        }
        $db->commit();
        $get = $db->prepare('SELECT body FROM history WHERE external=? AND class=? AND ends<? AND meeting<>? ORDER BY ends DESC,starts DESC,id LIMIT 13');
        $counts = [];
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $paths = $source['paths'][$year];
            $audit = Artifacts::lines($paths['audit']);
            $mean = Artifacts::lines($paths['sidecar']);
            $audit->rewind();
            $mean->rewind();
            $count = ['races' => 0, 'entries' => 0, 'numeric' => 0, 'null' => 0, 'zero' => 0, 'null_reasons' => []];
            $trendAudit = fopen($directory.'/audit-'.$year.'.jsonl', 'xb');
            if ($trendAudit === false) {
                throw new RuntimeException('Cannot create trend audit.');
            }
            try {
                $seal = JsonlArtifact::write($directory.'/trend-'.$year.'.jsonl', (function () use ($paths, $year, &$count, $audit, $mean, $get, $trendAudit) {
                    foreach (Artifacts::lines($paths['input']) as $race) {
                        Validator::race($race, $year);
                        if (! $mean->valid()) {
                            throw new RuntimeException('Missing fixed mean6 race.');
                        }
                        Dataset::cohort($race, $mean->current());
                        $extras = [];
                        foreach ($race['entries'] as $i => $entry) {
                            if (! $audit->valid()) {
                                throw new RuntimeException('Missing sealed audit entry.');
                            }
                            $a = $audit->current();
                            Files::same([$year, $race['race_id'], $entry['id'], $entry['bike']], [$a['year'], $a['race_id'], $a['entry_id'], $a['bike']], 'audit cohort');
                            $target = $a['context']['target'];
                            Files::same([$race['race_id'], $entry['id'], $entry['bike']], [$target['race_id'], $target['entry_id'], $target['bike']], 'audit fixed target');
                            if (substr($target['race_date'], 0, 4) !== (string) $year) {
                                throw new RuntimeException('Wrong fixed target year.');
                            }
                            $history = [];
                            if ($a['context']['reasons'] === []) {
                                $get->execute([$a['context']['evidence']['external_player_id'], $a['context']['class'], $target['meeting_start'], $target['meeting_id']]);
                                while ($body = $get->fetchColumn()) {
                                    $history[] = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                                }
                            }
                            $result = Trend::calculate($a, $history);
                            Files::same([$entry['id'], $entry['bike'], $result['stat35_mean6']], array_values($mean->current()['entries'][$i]), 'sealed mean6 sidecar');
                            $extras[] = ['id' => $entry['id'], 'bike' => $entry['bike'], 'stat35_mean6' => $result['stat35_mean6'], 'stat35_trend6' => $result['stat35_trend6']];
                            $count['entries']++;
                            $count[$result['stat35_trend6'] === null ? 'null' : 'numeric']++;
                            $count['zero'] += (int) ($result['stat35_trend6'] === 0.0);
                            if ($result['null_reason'] !== null) {
                                $count['null_reasons'][$result['null_reason']] = ($count['null_reasons'][$result['null_reason']] ?? 0) + 1;
                            }
                            $line = Files::canonical(['year' => $year, 'race_id' => $race['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike'], ...$result])."\n";
                            if (fwrite($trendAudit, $line) !== strlen($line)) {
                                throw new RuntimeException('Short audit write.');
                            }
                            $audit->next();
                        }
                        $count['races']++;
                        yield ['year' => $year, 'race_id' => $race['race_id'], 'entries' => $extras];
                        $mean->next();
                    }
                })());
            } finally {
                fclose($trendAudit);
            }
            if ($audit->valid() || $mean->valid() || [$count['races'], $count['entries']] !== [$source['expected_rows'][$year], $source['expected_entries'][$year]]) {
                throw new RuntimeException('Trend universe mismatch.');
            }
            $path = $directory.'/trend-'.$year.'.jsonl';
            $source['paths'][$year]['trend'] = $path;
            $source['seals'][$path] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
            ksort($count['null_reasons']);
            $counts[$year] = $count;
        }
        Sources::verify($source);
        JsonlArtifact::json($directory.'/summary.json', $counts);

        return $source;
    }
}
