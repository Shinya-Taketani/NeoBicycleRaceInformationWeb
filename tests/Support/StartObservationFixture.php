<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract as Audit;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\StartObservation\Contract;

final class StartObservationFixture
{
    // Synthetic named fields/header structure only; no real people, names or outcome data.
    public static function data(): array
    {
        $race = ['race_id' => 10, 'race_date' => '2024-01-01', 'track_code' => '22', 'race_number' => 1];
        $rows = $entries = [];
        for ($bike = 1; $bike <= 5; $bike++) {
            $row = array_fill_keys(Audit::ROW_KEYS, '');
            $row['syaban'] = (string) $bike;
            $row['sensyuRegistNo'] = '00000'.$bike;
            $row['kojinStateItemSubData'] = $bike === 1 ? [['kojinState' => 'S', 'kojinStateClass' => '', 'tyakuNote' => '']] : [];
            $rows[] = $row;
            $entries[] = ['id' => 100 + $bike, 'race_id' => 10, 'bike_number' => $bike, 'external_player_id' => '00000'.$bike];
        }

        return ['race' => $race, 'entries' => $entries, 'rows' => $rows, 'import' => ['race_date' => '2024-01-01', 'parsed_page_status' => 'RESULTS_AVAILABLE']];
    }

    public static function html(array $rows, ?array $headers = null, ?array $context = null, array $extra = []): string
    {
        $context ??= ['selKaisai' => '20240101', 'selKjyoCd' => '22', 'selRaceNo' => 1];

        return '<html><meta charset="UTF-8"><body><table id="rrDispTyakuJyun"><thead><tr>'
            .implode('', array_map(fn ($text) => '<td>'.$text.'</td>', $headers ?? Audit::HEADERS))
            .'</tr></thead><tbody id="rrTableTyakuJyunBody"></tbody></table><script>jsonData["PC0201"]='
            .json_encode(['C0201data' => $context], JSON_THROW_ON_ERROR).';jsonData["PJ0326"]='
            .json_encode($extra + ['tyakujyunDispFlg' => true, 'tyakujyunItemSubData' => $rows], JSON_THROW_ON_ERROR).';</script></body></html>';
    }

    public static function bundle(string $directory, int $races = 1, int $versions = 2, int $padding = 0): array
    {
        mkdir($directory);
        $f = self::data();
        file_put_contents($directory.'/raw.html', self::html($f['rows']));
        $seal = Files::identity($directory.'/raw.html');
        $raw = ['import_id' => 1, 'race_id' => 10, 'race_date' => '2024-01-01', 'absolute_path' => $directory.'/raw.html',
            'raw_file_path' => 'raw.html', 'source_hash' => $seal['sha256'], 'raw_response_size' => $seal['bytes'],
            'converted_hash' => $seal['sha256'], 'fetch_hash' => $seal['sha256'], 'fetch_bytes' => $seal['bytes'],
            'fetch_path' => 'raw.html', 'content_type' => 'text/html; charset=UTF-8', 'fetched_at' => '2026-07-21T00:00:00+09:00',
            'parsed_page_status' => 'RESULTS_AVAILABLE', 'parser_version' => 'SYNTHETIC'];
        JsonlArtifact::write($directory.'/database-inventory.jsonl', (function () use ($races, $versions, $padding, $f, $raw) {
            for ($i = 0; $i < $races; $i++) {
                $race = array_replace($f['race'], ['race_id' => 10 + $i]);
                $entries = array_map(fn ($e) => array_replace($e, ['race_id' => 10 + $i, 'id' => $i * 5 + $e['id']]), $f['entries']);
                $imports = [];
                for ($v = 0; $v < $versions; $v++) {
                    $imports[] = array_replace($raw, ['race_id' => 10 + $i, 'import_id' => 1 + $i * $versions + $v]);
                }
                yield ['race' => $race, 'entries' => $entries, 'imports' => $imports, 'padding' => str_repeat('x', $padding)];
            }
        })());
        JsonlArtifact::write($directory.'/raw-source-inventory.jsonl', (function () use ($races, $versions, $raw) {
            for ($i = 0; $i < $races; $i++) {
                for ($v = 0; $v < $versions; $v++) {
                    yield array_replace($raw, ['race_id' => 10 + $i, 'import_id' => 1 + $i * $versions + $v])
                        + ['raw_sha256' => $raw['source_hash'], 'raw_bytes' => $raw['raw_response_size']];
                }
            }
        })());

        return self::seal($directory);
    }

    public static function seal(string $directory): array
    {
        foreach (['manifest.json', 'LOCKED.json'] as $name) {
            if (is_file($directory.'/'.$name)) {
                unlink($directory.'/'.$name);
            }
        }
        $files = [];
        foreach (['database-inventory.jsonl', 'raw-source-inventory.jsonl'] as $name) {
            $files[$name] = Files::json($directory.'/'.$name.'.manifest.json');
            $files[$name.'.manifest.json'] = Files::identity($directory.'/'.$name.'.manifest.json');
        }
        JsonlArtifact::json($directory.'/manifest.json', ['status' => 'AUDIT_LOCKED', 'audit_id' => basename(Contract::LEDGER), 'contract' => Audit::plan(), 'files' => $files]);
        $pin = Files::identity($directory.'/manifest.json');
        JsonlArtifact::json($directory.'/LOCKED.json', $pin);

        return $pin;
    }
}
