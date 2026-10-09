<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as Original;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use Generator;
use PDO;
use RuntimeException;

final class Input
{
    public static function validate(array $race, array $years = [2024, 2025]): void
    {
        if (! in_array($race['year'] ?? null, $years, true) || array_diff($years, [2022, 2023, 2024, 2025]) !== []) {
            throw new RuntimeException('Forbidden composition input year.');
        }
        $original = $race;
        if (! is_array($original['entries'] ?? null)) {
            throw new RuntimeException('Invalid composition entry list.');
        }
        foreach ($original['entries'] as &$entry) {
            Original::keys($entry, [...Original::ENTRY_KEYS, 'stat35_mean6']);
            $value = $entry['stat35_mean6'];
            if ($value !== null && ((! is_float($value) && ! is_int($value)) || ! is_finite($value))) {
                throw new RuntimeException('Invalid nullable STAT35 mean6.');
            }
            unset($entry['stat35_mean6']);
        }
        unset($entry);
        Validator::race($original, $race['year']);
    }

    public static function write(string $path, iterable $races): array
    {
        $seal = JsonlArtifact::write($path, $races);
        JsonlArtifact::json($path.'.input.json', ['version' => Contract::INPUT_VERSION, 'purpose' => 'FEATURE_ONLY_TECHNICAL_PREDICTION', 'data' => $seal]);

        return $seal;
    }

    public static function read(string $path): Generator
    {
        $metadata = Files::json($path.'.input.json');
        Files::same(['version' => Contract::INPUT_VERSION, 'purpose' => 'FEATURE_ONLY_TECHNICAL_PREDICTION',
            'data' => Files::json($path.'.manifest.json')], $metadata, 'public feature input contract');
        Files::verify($path, $metadata['data']);
        // Disk-backed identities reject nonmonotone duplicates without retaining the annual universe.
        $db = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-1024');
        $db->exec('CREATE TABLE seen (kind TEXT, id INTEGER, PRIMARY KEY(kind,id))');
        $insert = $db->prepare('INSERT INTO seen VALUES (?,?)');
        $db->beginTransaction();
        try {
            foreach (JsonlArtifact::read($path) as $race) {
                self::validate($race);
                $insert->execute(['race', $race['race_id']]);
                foreach ($race['entries'] as $entry) {
                    $insert->execute(['entry', $entry['id']]);
                }
                yield $race;
            }
        } finally {
            $db->rollBack();
        }
        Files::verify($path, $metadata['data']);
        Files::same($metadata, Files::json($path.'.input.json'), 'input metadata end');
    }

    public static function modelRace(array $race): array
    {
        foreach ($race['entries'] as &$entry) {
            $entry['signals'] = [...$entry['signals'], ...$entry['history'], $entry['stat35_mean6']];
            unset($entry['history'], $entry['history_status'], $entry['stat35_mean6']);
        }
        unset($entry);

        return $race;
    }
}
