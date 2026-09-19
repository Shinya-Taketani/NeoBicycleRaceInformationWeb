<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use Generator;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class Service
{
    public function __construct(private readonly OuterSource $outer, private readonly Query $query, private readonly ReadOnlySession $session,
        private readonly Bundle $bundle, private readonly Code $code) {}

    public function capture(string $root, string $id, string $outerRoot): array
    {
        $source = $this->outer->open($outerRoot);
        $code = $this->code->capture(false);
        $this->bundle->prepare($root, $id, $source['files']);

        return $this->bundle->writer->locked($root, $id, function () use ($root, $id, $source, $code): array {
            $stage = Files::directory($root.'/.staging/'.$id.'-'.bin2hex(random_bytes(6)));
            $w = new Workspace($stage.'/spool.sqlite');
            $w->targets($this->outer->targets($source));
            $audit = ['accessed_tables' => Contract::plan()['tables'], 'selected_columns' => Contract::COLUMNS,
                'query_year_min' => 2022, 'query_year_max' => 2025, 'database_write_count' => 0, '2026_access_count' => 0];
            $expected = [];
            $digest = [];
            $settings = $this->session->run(function ($settings) use ($w, $stage, &$audit, &$expected, &$digest): array {
                $this->timeout();
                $this->resolveTargets($w, $audit);
                $players = array_map('intval', $w->db->query('SELECT DISTINCT player_id FROM targets WHERE player_id IS NOT NULL ORDER BY player_id')->fetchAll(PDO::FETCH_COLUMN));
                $nulls = array_map('intval', $w->db->query('SELECT entry_id FROM targets WHERE player_id IS NULL ORDER BY entry_id')->fetchAll(PDO::FETCH_COLUMN));
                $expected += $this->bundle->writer->writeJsonl($stage, 'score-observations.jsonl', $this->digest($this->query->rows($players, $nulls, $audit), $digest));

                return $settings + ['statement_timeout' => '120000ms'];
            });
            $w->observations(JsonlArtifact::read($stage.'/score-observations.jsonl'));
            if ($w->db->query('SELECT t.entry_id FROM targets t JOIN observations o ON o.entry_id=t.entry_id WHERE t.player_id IS NOT o.player_id LIMIT 1')->fetch()) {
                throw new RuntimeException('Target player drift.');
            }
            $end = [];
            $endSettings = $this->session->run(function ($settings) use ($w, &$audit, &$end): array {
                $this->timeout();
                $this->resolveTargets($w, $audit, true);
                $players = array_map('intval', $w->db->query('SELECT DISTINCT player_id FROM targets WHERE player_id IS NOT NULL ORDER BY player_id')->fetchAll(PDO::FETCH_COLUMN));
                $nulls = array_map('intval', $w->db->query('SELECT entry_id FROM targets WHERE player_id IS NULL ORDER BY entry_id')->fetchAll(PDO::FETCH_COLUMN));
                foreach ($this->digest($this->query->rows($players, $nulls, $audit), $end) as $_) {
                }

                return $settings + ['statement_timeout' => '120000ms'];
            });
            Files::same($digest, $end, 'score START/END');
            Files::same($settings, $endSettings, 'source READ ONLY settings');
            $coverage = $w->db->query('SELECT substr(date,1,4) AS year,count(*) AS observations,count(DISTINCT race_id) AS races,count(DISTINCT player_id) AS players,sum(score IS NULL) AS missing_score,count(DISTINCT meeting_id) AS meetings FROM observations GROUP BY year ORDER BY year')->fetchAll();
            $targets = $w->db->query('SELECT year,count(DISTINCT race_id) AS races,count(*) AS entries,count(DISTINCT player_id) AS players,sum(player_id IS NULL) AS missing_player FROM targets GROUP BY year ORDER BY year')->fetchAll();
            $audit += ['database_read_only' => $settings, 'target_counts' => $targets,
                'target_races' => array_sum(array_column($targets, 'races')), 'target_entries' => array_sum(array_column($targets, 'entries')),
                'target_players' => (int) $w->db->query('SELECT count(DISTINCT player_id) FROM targets')->fetchColumn(),
                'returned_entries' => $digest['entries'], 'returned_races' => array_sum(array_column($coverage, 'races'))];
            $rows = (function () use ($w): Generator {
                foreach ($w->db->query('SELECT * FROM targets ORDER BY year,race_id,entry_id') as $row) {
                    yield $row;
                }
            })();
            $expected += $this->bundle->writer->writeJsonl($stage, 'targets.jsonl', $rows);
            foreach (['contract.json' => Contract::plan(), 'target-universe.json' => ['counts' => $targets, 'source' => $source, 'identity' => $expected['targets.jsonl']],
                'source-settings.json' => $settings, 'coverage.json' => $coverage, 'source-query-audit.json' => $audit,
                'code.json' => $code, 'source-end.json' => ['status' => 'UNCHANGED', 'start' => $digest, 'end' => $end, 'settings' => $endSettings]] as $name => $data) {
                $expected += $this->bundle->writer->writeJson($stage, $name, $data);
            }
            OuterSource::verify($source['files']);
            Files::same($code, $this->code->capture(false), 'source code END');
            unset($w);
            unlink($stage.'/spool.sqlite');
            $this->bundle->publish($stage, $root.'/evaluations/'.$id, Contract::plan(), $expected);

            return ['status' => 'SCORE_SOURCE_LOCKED', 'bundle' => $root.'/evaluations/'.$id, 'targets' => $targets, 'coverage' => $coverage, 'memory_peak' => memory_get_peak_usage(true)];
        });
    }

    public function verify(string $path): array
    {
        $m = $this->bundle->verify($path, Contract::plan());
        Files::same(Files::json($path.'/code.json'), $this->code->capture(false), 'score source code');

        return ['status' => 'VERIFIED', 'files' => count($m['files']), 'database' => 'NONE'];
    }

    private function resolveTargets(Workspace $w, array &$audit, bool $end = false): void
    {
        $last = 0;
        do {
            $q = $w->db->prepare('SELECT * FROM targets WHERE entry_id>? ORDER BY entry_id LIMIT 1000');
            $q->execute([$last]);
            $targets = $q->fetchAll();
            $byId = array_column($targets, null, 'entry_id');
            $count = 0;
            $w->db->beginTransaction();
            foreach ($this->query->rows([], array_keys($byId), $audit, true) as $row) {
                $t = $byId[$row['entry_id']] ?? null;
                if ($t === null || $t['race_id'] !== $row['race_id'] || $t['bike'] !== $row['bike'] || $t['year'] !== (int) substr($row['race_date'], 0, 4)
                    || ($end && $t['player_id'] !== $row['player_id'])) {
                    throw new RuntimeException('DB target identity mismatch.');
                }
                if (! $end) {
                    $u = $w->db->prepare('UPDATE targets SET player_id=? WHERE entry_id=?');
                    $u->execute([$row['player_id'], $row['entry_id']]);
                }
                $count++;
            }
            $w->db->commit();
            if ($count !== count($targets)) {
                throw new RuntimeException('Missing fixed DB targets.');
            }
            $last = $targets === [] ? $last : end($targets)['entry_id'];
        } while (count($targets) === 1000);
    }

    private function timeout(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL statement_timeout = '120000ms'");
        }
    }

    private function digest(iterable $rows, array &$digest): Generator
    {
        $hash = hash_init('sha256');
        $n = 0;
        foreach ($rows as $row) {
            hash_update($hash, Files::canonical($row)."\n");
            $n++;
            yield $row;
        }
        $digest = ['entries' => $n, 'sha256' => hash_final($hash)];
    }
}
