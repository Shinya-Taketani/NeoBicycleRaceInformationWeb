<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\TacticalInputReadiness;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Context\ReadOnlySession;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Database\Connection;
use RuntimeException;

final class Auditor
{
    public function __construct(private readonly Universe $universe, private readonly ReadOnlySession $session) {}

    public function audit(string $output, bool $authorized): array
    {
        if (! $authorized) {
            throw new RuntimeException('Explicit read-only authorization required.');
        }
        Contract::output($output, array_column(Universe::FIXED, 'path'));
        $code = Contract::code();
        try {
            $source = $this->universe->prepare($output);
            $records = new Stream($output.'/records.jsonl');
            $queries = new Stream($output.'/queries.jsonl');
            $hashes = [];
            $settings = $this->session->run(function (Connection $db, array $settings) use ($output, $source, $records, $queries, &$hashes): void {
                // Only schema metadata is read before the SQL-limited racecard extraction.
                $columns = $db->select("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='race_entries' AND column_name IN ('id','race_id','bike_number','player_id','external_player_id','riding_style','line_text','fetched_at')");
                if (count($columns) !== 8) {
                    throw new RuntimeException('Required racecard schema unavailable.');
                }
                foreach (['START', 'END'] as $pass) {
                    $hash = hash_init('sha256');
                    $pending = [];
                    $count = 0;
                    foreach (Artifacts::lines($output.'/targets.jsonl') as $race) {
                        foreach ($race['entries'] as $target) {
                            $pending[] = $target;
                            if (count($pending) === 500) {
                                $this->chunk($db, $pending, $pass, $records, $queries, $hash);
                                $count += count($pending);
                                $pending = [];
                            }
                        }
                    }
                    if ($pending !== []) {
                        $this->chunk($db, $pending, $pass, $records, $queries, $hash);
                        $count += count($pending);
                    }
                    if ($count !== array_sum(array_column($source['years'], 'entries'))) {
                        throw new RuntimeException('Extraction count mismatch.');
                    }
                    $hashes[$pass] = hash_final($hash);
                }
                if ($hashes['START'] !== $hashes['END']) {
                    throw new RuntimeException('Racecard attribute START/END drift.');
                }
            });
            $files = ['targets.jsonl' => $source['target_seal'], 'records.jsonl' => $records->finish(),
                'queries.jsonl' => $queries->finish(),
                'source-audit.json' => Artifacts::json($output, 'source-audit.json', ['connection' => $settings,
                    'source' => $source, 'attribute_hashes' => $hashes, 'extracted_at' => date(DATE_ATOM),
                    'field_observation_evidence' => 'NOT_AVAILABLE_GENERIC_TIMESTAMP_ONLY'])];
            Universe::verify($source['seals']);
            Files::same($code, Contract::code(), 'audit code START/END');
            Artifacts::publish($output, ['version' => Contract::VERSION, 'kind' => 'AUDIT', 'historical_as_of_available' => false,
                'prediction_use' => 'NOT_AUTHORIZED', 'years' => $source['years'], 'files' => $files, 'code' => $code]);

            return ['status' => 'AUDITED', 'years' => $source['years'], 'attribute_hashes' => $hashes];
        } catch (\Throwable $e) {
            Artifacts::json($output, 'FAILED.json', ['error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function query(array $targets): array
    {
        $bindings = [];
        foreach ($targets as $t) {
            Contract::target(array_intersect_key($t, array_flip(\App\Domain\Keirin\Statistics\AgariC1Context\Contract::TARGET_FIELDS)), (int) substr($t['race_date'], 0, 4));
            array_push($bindings, $t['entry_id'], $t['race_id'], $t['race_date']);
        }
        if ($targets === [] || count($targets) > 500) {
            throw new RuntimeException('Invalid extraction chunk.');
        }
        $values = implode(',', array_fill(0, count($targets), '(CAST(? AS BIGINT), CAST(? AS BIGINT), CAST(? AS DATE))'));
        $sql = 'WITH targets(entry_id,race_id,race_date) AS (VALUES '.$values.')
            SELECT t.entry_id AS target_id, e.id AS entry_id, r.id AS race_id, e.bike_number AS bike, e.player_id,
                e.external_player_id, r.race_date::text, r.scheduled_start_at::text, e.riding_style, e.line_text, e.fetched_at::text
            FROM targets t LEFT JOIN (race_entries e INNER JOIN races r ON r.id=e.race_id
                AND r.source=\'keirin_jp\' AND r.race_date BETWEEN DATE \'2022-01-01\' AND DATE \'2025-12-31\')
                ON e.id=t.entry_id AND r.id=t.race_id AND r.race_date=t.race_date';

        return [$sql, $bindings];
    }

    private function chunk(Connection $db, array $targets, string $pass, Stream $records, Stream $queries, \HashContext $hash): void
    {
        [$sql, $bindings] = self::query($targets);
        $queries->row(['pass' => $pass, 'target_entries' => count($targets), 'sql' => $sql, 'bindings' => $bindings]);
        $found = [];
        foreach ($db->select($sql, $bindings) as $r) {
            $r = (array) $r;
            $key = (int) $r['target_id'];
            if (isset($found[$key])) {
                throw new RuntimeException('Duplicate database entry.');
            }
            unset($r['target_id']);
            foreach (['entry_id', 'race_id', 'bike', 'player_id'] as $field) {
                $r[$field] = $r[$field] === null ? null : (int) $r[$field];
            }
            $found[$key] = $r;
        }
        if (count($found) !== count($targets)) {
            throw new RuntimeException('Unexpected extraction rows.');
        }
        foreach ($targets as $target) {
            $row = ['target_entry_id' => $target['entry_id'], 'record' => $found[$target['entry_id']] ?? throw new RuntimeException('Missing target response.')];
            hash_update($hash, Files::canonical($row)."\n");
            if ($pass === 'START') {
                $records->row($row);
            }
        }
    }
}
