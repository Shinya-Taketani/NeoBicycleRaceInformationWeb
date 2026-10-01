<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Console\Commands\Keirin\StartCountSnapshotCommand;
use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract as Audit;
use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\LineWriter;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\ConnectionGuard;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use RuntimeException;

final class Bundle
{
    public static function verify(string $path, string $kind): array
    {
        if (realpath($path) !== $path) {
            throw new RuntimeException('Noncanonical bundle path.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/COMPLETE.json'));
        $m = Files::json($path.'/manifest.json');
        if (($m['version'] ?? null) !== Contract::VERSION || ($m['kind'] ?? null) !== $kind) {
            throw new RuntimeException('Wrong start count bundle contract.');
        }
        foreach ($m['files'] as $name => $seal) {
            if (basename($name) !== $name || str_contains($name, '..')) {
                throw new RuntimeException('Unsafe bundle path.');
            }
            Files::verify($path.'/'.$name, $seal);
        }

        return $m;
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach ([StartCountSnapshotCommand::class, Audit::class, RawReader::class, Files::class, LineWriter::class,
            Artifacts::class, JsonlArtifact::class, ResultStore::class, EmbeddedJsonExtractor::class,
            CharacterEncodingConverter::class, ConnectionGuard::class, Ledger::class,
            \App\Domain\Keirin\Statistics\StartObservation\Contract::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        $paths[] = base_path('composer.lock');
        sort($paths, SORT_STRING);
        $code = [];
        foreach (array_unique($paths) as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }

        return $code;
    }

    public static function target(array $item): array
    {
        $r = $item['race'];
        Audit::date($r['race_date']);
        $race = [];
        foreach (['race_id', 'race_date', 'track_code', 'race_number'] as $field) {
            $race[$field] = $r[$field];
        }
        $ids = [];
        foreach ($item['imports'] as $import) {
            $id = $import['scraping_fetch_log_id'] ?? null;
            if ($id !== null) {
                $ids[Audit::id($id)] = true;
            }
        }

        return ['race' => $race, 'entries' => array_map(static fn ($e) => [
            'id' => $e['id'], 'race_id' => $e['race_id'], 'bike_number' => $e['bike_number'],
            'external_player_id' => $e['external_player_id'],
        ], $item['entries']), 'historical_fetch_ids' => array_keys($ids)];
    }
}
