<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Context;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Database\Connection;
use RuntimeException;

final class Extractor
{
    public function __construct(private readonly Targets $targets, private readonly ReadOnlySession $session) {}

    public function extract(string $c1, string $output, bool $authorized, int $chunk = 500): array
    {
        if (! $authorized || $chunk < 1 || $chunk > 1000) {
            throw new RuntimeException('Explicit read-only authorization and chunk 1..1000 required.');
        }
        $code = Contract::code();
        Contract::output($output, [$c1]);
        $completed = 0;
        try {
            // The entire fixed universe is validated before opening the production connection.
            $source = $this->targets->prepare($c1, $output);
            $raw = new Stream($output.'/db-records.jsonl');
            $queries = new Stream($output.'/queries.jsonl');
            $settings = $this->session->run(function (Connection $db, array $settings) use ($output, $chunk, $raw, $queries, &$completed): void {
                Artifacts::json($output, 'connection.json', $settings + ['extracted_at' => date(DATE_ATOM)]);
                $pending = [];
                foreach (Artifacts::lines($output.'/targets.jsonl') as $race) {
                    foreach ($race['entries'] as $target) {
                        Contract::target($target, $race['year']);
                        $pending[] = $target;
                        if (count($pending) === $chunk) {
                            $this->chunk($db, $pending, $raw, $queries, $completed);
                            $pending = [];
                        }
                    }
                }
                if ($pending !== []) {
                    $this->chunk($db, $pending, $raw, $queries, $completed);
                }
            });
            // run() has rolled back the read-only transaction and disconnected before publication.
            $files = ['targets.jsonl' => $source['target_seal'], 'db-records.jsonl' => $raw->finish(),
                'queries.jsonl' => $queries->finish(), 'connection.json' => Files::identity($output.'/connection.json')];
            Targets::verify($source['seals']);
            Files::same($code, Contract::code(), 'extraction code start/end');
            $manifest = ['version' => Contract::VERSION, 'kind' => 'EXTRACTION', 'status' => 'REVIEW_PENDING',
                'historical_as_of_available' => false, 'source' => $source, 'connection' => $settings,
                'completed_entries' => $completed, 'chunk_entries' => $chunk, 'columns' => Contract::COLUMNS,
                'tables' => Contract::TABLES, 'code' => $code, 'files' => $files];
            Artifacts::publish($output, $manifest);

            return ['status' => 'EXTRACTED_REVIEW_PENDING', 'years' => $source['years'], 'entries' => $completed];
        } catch (\Throwable $e) {
            Artifacts::json($output, 'FAILED.json', ['status' => 'FAILED', 'completed_entries' => $completed,
                'retry_authorized' => false, 'message' => $e->getMessage(), 'error_class' => $e::class]);
            throw $e;
        }
    }

    public static function query(array $targets): array
    {
        if ($targets === [] || count($targets) > 1000) {
            throw new RuntimeException('Invalid extraction chunk.');
        }
        $bindings = [];
        $races = [];
        foreach ($targets as $t) {
            Contract::target($t, (int) substr($t['race_date'], 0, 4));
            $bindings[] = $t['entry_id'];
            $races[] = $t['race_id'];
        }
        $races = array_values(array_unique($races));
        $columns = ['t.entry_id AS target_entry_id'];
        foreach (Contract::COLUMNS as $alias => $names) {
            foreach ($names as $name) {
                $columns[] = $alias.'.'.$name.' AS '.$alias.'_'.$name;
            }
        }
        $values = implode(',', array_fill(0, count($targets), '(CAST(? AS BIGINT))'));
        $ids = implode(',', array_fill(0, count($races), '?'));
        // The guarded inner pair prevents even entry fields from escaping the permitted race universe.
        // A missing/out-of-scope pair stays as a NULL left join; never infer which cause it was.
        $sql = 'WITH targets(entry_id) AS (VALUES '.$values.') SELECT '.implode(', ', $columns).'
            FROM targets t
            LEFT JOIN (race_entries entry INNER JOIN races race ON race.id=entry.race_id
                AND race.source=? AND race.race_date BETWEEN ? AND ? AND race.id IN ('.$ids.')) ON entry.id=t.entry_id
            LEFT JOIN race_days day ON day.id=race.race_day_id AND day.race_date BETWEEN ? AND ?
            LEFT JOIN race_meetings meeting ON meeting.id=day.race_meeting_id AND meeting.source=?
                AND meeting.starts_on<=? AND meeting.ends_on>=?';

        return [$sql, [...$bindings, 'keirin_jp', '2022-01-01', '2025-12-31', ...$races,
            '2022-01-01', '2025-12-31', 'keirin_jp', '2025-12-31', '2022-01-01']];
    }

    private function chunk(Connection $db, array $targets, Stream $raw, Stream $queries, int &$completed): void
    {
        [$sql, $bindings] = self::query($targets);
        $queries->row(['offset' => $completed, 'count' => count($targets), 'sql' => $sql, 'bindings' => $bindings]);
        $records = [];
        $allowed = array_fill_keys(array_column($targets, 'entry_id'), true);
        foreach ($db->select($sql, $bindings) as $row) {
            $row = (array) $row;
            $id = (int) $row['target_entry_id'];
            if (! isset($allowed[$id]) || isset($records[$id])) {
                throw new RuntimeException('Unexpected or duplicate database join.');
            }
            $records[$id] = ['entry_id' => $id];
            foreach (Contract::COLUMNS as $alias => $names) {
                $fields = [];
                foreach ($names as $name) {
                    $fields[$name] = $row[$alias.'_'.$name];
                }
                $records[$id][$alias] = $fields['id'] === null ? null : $fields;
            }
        }
        foreach ($targets as $target) {
            if (! isset($records[$target['entry_id']])) {
                throw new RuntimeException('Database lost a target left join.');
            }
            $raw->row($records[$target['entry_id']]);
            $completed++;
        }
    }
}
