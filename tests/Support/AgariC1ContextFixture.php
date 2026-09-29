<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Statistics\AgariC1Context\Contract;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Database\SQLiteConnection;
use PDO;

/** Synthetic metadata only; no real rider, race, or acquisition record. */
final class AgariC1ContextFixture
{
    public static function race(int $year = 2024, int $id = 71): array
    {
        $r = AgariC1InputFixture::race($year, $id);
        $targets = $records = [];
        foreach ($r['entries'] as $e) {
            $target = AgariC1InputFixture::target($r, $e);
            $target['player_id'] = $e['bike'];
            $targets[] = array_intersect_key($target, array_flip(Contract::TARGET_FIELDS));
            $records[] = ['entry_id' => $e['id'],
                'entry' => ['id' => $e['id'], 'race_id' => $id, 'player_id' => $e['bike'],
                    'external_player_id' => sprintf('%06d', $e['bike']), 'bike_number' => $e['bike'], 'fetched_at' => null],
                'race' => ['id' => $id, 'source' => 'keirin_jp', 'external_race_id' => 'synthetic:'.$id,
                    'race_day_id' => $id, 'race_date' => $year.'-08-01', 'race_number' => 1, 'race_type' => 'S級予選'],
                'day' => ['id' => $id, 'race_meeting_id' => $id, 'race_date' => $year.'-08-01'],
                'meeting' => ['id' => $id, 'source' => 'keirin_jp', 'starts_on' => $year.'-08-01', 'ends_on' => $year.'-08-03']];
        }

        return [['year' => $year, 'race_id' => $id, 'entries' => $targets], $records];
    }

    public static function source(string $path, int $racesPerYear = 1, ?callable $mutate = null): array
    {
        Artifacts::create($path);
        $years = [];
        foreach (InputContract::YEARS as $year) {
            $input = new Stream($path.'/inputs-'.$year.'.jsonl');
            $history = new Stream($path.'/history-'.$year.'.jsonl');
            for ($i = $racesPerYear; $i >= 1; $i--) {
                $id = ($year - 2021) * 100000 + $i;
                $race = AgariC1InputFixture::race($year, $id);
                [$target] = self::race($year, $id);
                if ($mutate !== null) {
                    $mutate($race, $target);
                }
                $input->row($race);
                foreach ($target['entries'] as $t) {
                    $history->row(['target' => $t, 'aggregate' => ['values' => 'MUST_NOT_BE_USED'], 'cache' => 'MUST_NOT_BE_USED']);
                }
            }
            $years[$year] = ['inputs' => ['rows' => $racesPerYear, ...$input->finish()],
                'history' => ['rows' => 5 * $racesPerYear, ...$history->finish()]];
        }

        return Artifacts::json($path, 'manifest.json', ['calculation_version' => InputContract::C1_VERSION, 'manifests' => $years]);
    }

    public static function database(int $racesPerYear = 1, bool $primaryKeys = true): SQLiteConnection
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = new SQLiteConnection($pdo, ':memory:', '', ['driver' => 'sqlite', 'database' => ':memory:']);
        foreach (Contract::COLUMNS as $alias => $columns) {
            $ddl = [];
            foreach ($columns as $column) {
                $numeric = in_array($column, ['id', 'race_id', 'player_id', 'bike_number', 'race_number', 'race_day_id', 'race_meeting_id'], true);
                $ddl[] = $column.' '.($numeric ? 'INTEGER' : 'TEXT').($primaryKeys && $column === 'id' ? ' PRIMARY KEY' : '');
            }
            $pdo->exec('CREATE TABLE '.Contract::TABLES[$alias].' ('.implode(',', $ddl).')');
        }
        $pdo->beginTransaction();
        foreach (InputContract::YEARS as $year) {
            for ($i = 1; $i <= $racesPerYear; $i++) {
                [, $records] = self::race($year, ($year - 2021) * 100000 + $i);
                foreach ($records as $offset => $record) {
                    foreach (Contract::TABLES as $alias => $table) {
                        if ($alias !== 'entry' && $offset !== 0) {
                            continue;
                        }
                        $values = $record[$alias];
                        $pdo->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')')
                            ->execute(array_values($values));
                    }
                }
            }
        }
        $pdo->commit();

        return $db;
    }
}
