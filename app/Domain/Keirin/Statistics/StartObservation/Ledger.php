<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartObservation;

use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract as Audit;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Generator;
use RuntimeException;

final class Ledger
{
    private const INPUTS = ['database-inventory.jsonl', 'database-inventory.jsonl.manifest.json',
        'raw-source-inventory.jsonl', 'raw-source-inventory.jsonl.manifest.json'];

    public function __construct(private readonly array $pin = Contract::LEDGER_SEAL) {}

    public function open(string $directory): array
    {
        if (realpath($directory) !== $directory) {
            throw new RuntimeException('Noncanonical ledger path.');
        }
        Files::verify($directory.'/manifest.json', $this->pin);
        Files::verify($directory.'/manifest.json', Files::json($directory.'/LOCKED.json'));
        $m = Files::json($directory.'/manifest.json');
        if (($m['status'] ?? null) !== 'AUDIT_LOCKED'
            || ($m['audit_id'] ?? null) !== basename(Contract::LEDGER)) {
            throw new RuntimeException('Not the accepted PR64 ledger.');
        }
        Files::same(Audit::plan(), $m['contract'], 'accepted audit contract');
        $seals = [];
        foreach (self::INPUTS as $name) {
            $seals[$name] = $m['files'][$name] ?? throw new RuntimeException('Missing ledger seal.');
        }
        // Reuse the audited byte/row/sidecar verifier, not the old DB audit execution.
        app(ResultStore::class)->verifyGenerated($directory, $seals);

        return ['path' => $directory, 'manifest' => $this->pin, 'files' => $seals];
    }

    public function verify(array $source): void
    {
        Files::verify($source['path'].'/manifest.json', $source['manifest']);
        Files::verify($source['path'].'/manifest.json', Files::json($source['path'].'/LOCKED.json'));
        foreach ($source['files'] as $name => $seal) {
            Files::verify($source['path'].'/'.$name, $seal);
        }
    }

    public function races(array $source): Generator
    {
        $raws = Artifacts::lines($source['path'].'/raw-source-inventory.jsonl');
        $priorRace = 0;
        $races = $imports = 0;
        foreach (Artifacts::lines($source['path'].'/database-inventory.jsonl') as $line) {
            $race = $line['race'];
            Audit::date($race['race_date']);
            $id = Audit::id($race['race_id']);
            if ($id <= $priorRace || ! is_array($line['entries']) || ! array_is_list($line['entries'])
                || ! is_array($line['imports']) || ! array_is_list($line['imports'])) {
                throw new RuntimeException('Invalid/duplicate ledger race.');
            }
            $priorRace = $id;
            $races++;
            $seen = [];
            foreach ($line['entries'] as $entry) {
                $bike = $entry['bike_number'] ?? null;
                if (($entry['race_id'] ?? null) !== $id || ! is_int($bike) || $bike < 1 || $bike > 9 || isset($seen[$bike])
                    || ! is_string($entry['external_player_id'] ?? null) || ! preg_match('/\A[0-9]{6}\z/D', $entry['external_player_id'])) {
                    throw new RuntimeException('Invalid ledger entry identity.');
                }
                $seen[$bike] = Audit::id($entry['id']);
            }
            $joined = [];
            $lastImport = 0;
            foreach ($line['imports'] as $import) {
                Audit::date($import['race_date']);
                if (Audit::id($import['import_id']) <= $lastImport || $import['race_id'] !== $id || $import['race_date'] !== $race['race_date'] || ! $raws->valid()) {
                    throw new RuntimeException('Invalid ledger import correspondence.');
                }
                $lastImport = $import['import_id'];
                $raw = $raws->current();
                Audit::date($raw['race_date']);
                foreach ($import as $key => $value) {
                    if (! array_key_exists($key, $raw) || $raw[$key] !== $value) {
                        throw new RuntimeException('Raw inventory/import mismatch: '.$key);
                    }
                }
                if (($raw['fetch_path'] ?? null) !== null && $raw['fetch_path'] !== $raw['raw_file_path']) {
                    throw new RuntimeException('Fetch path mismatch.');
                }
                if (($raw['raw_sha256'] ?? null) !== $raw['source_hash'] || ($raw['raw_bytes'] ?? null) !== $raw['raw_response_size']) {
                    throw new RuntimeException('Accepted Raw seal mismatch.');
                }
                $joined[] = $raw;
                $imports++;
                $raws->next();
            }
            yield ['race' => $race, 'entries' => $line['entries'], 'imports' => $joined];
        }
        if ($raws->valid() || $races !== $source['files']['database-inventory.jsonl']['rows']
            || $imports !== $source['files']['raw-source-inventory.jsonl']['rows']) {
            throw new RuntimeException('Ledger denominator mismatch.');
        }
    }
}
