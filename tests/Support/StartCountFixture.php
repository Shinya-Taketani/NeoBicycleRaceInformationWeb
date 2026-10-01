<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Exporter;
use App\Domain\Keirin\Statistics\StartCountSnapshot\MetadataSource;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;

final class StartCountFixture
{
    public static function target(): array
    {
        $f = StartObservationFixture::data();

        return ['race' => $f['race'], 'entries' => $f['entries']];
    }

    public static function page(): array
    {
        $summaries = $rows = [];
        foreach (self::target()['entries'] as $entry) {
            $summaries[] = ['carNum' => $entry['bike_number'], 'numPlayer' => $entry['external_player_id']];
            $rows[] = ['syaban' => (string) $entry['bike_number'], 'sensyuRegistNo' => $entry['external_player_id'],
                'stTori' => $entry['bike_number'] === 1 ? 0 : (string) $entry['bike_number']];
        }

        return ['PC0201' => ['C0201data' => ['selKaisai' => '20240101', 'selKjyoCd' => '22', 'selRaceNo' => 1,
            'C0201racedtl' => ['C0201sensyu' => $summaries]]], 'PJ0315' => ['sensyuTypeInfo' => $rows]];
    }

    public static function html(?array $page = null): string
    {
        $html = '<html><meta charset="UTF-8"><table><thead><tr><th>S</th></tr></thead></table><script>';
        foreach ($page ?? self::page() as $key => $value) {
            $html .= 'jsonData["'.$key.'"]='.json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION).';';
        }

        return $html.'</script></html>';
    }

    public static function bundle(string $root, int $races = 1, int $versions = 2, int $padding = 0): Ledger
    {
        $pin = StartObservationFixture::bundle($root.'/ledger', $races, 0, $padding);
        $ledger = new Ledger($pin);
        file_put_contents($root.'/detail.html', self::html());
        $raw = Files::identity($root.'/detail.html');
        $metadata = new class($races, $versions, $raw) extends MetadataSource
        {
            public function __construct(private int $races, private int $versions, private array $raw) {}

            public function capture(string $scope, callable $save): array
            {
                $save('race-requests.jsonl', (function () {
                    for ($i = 0; $i < $this->races; $i++) {
                        yield ['race_id' => 10 + $i, 'source' => 'keirin_jp', 'race_date' => '2024-01-01',
                            'race_number' => 1, 'encrypted_parameter' => 'synthetic-'.$i];
                    }
                })());
                $save('historical-requests.jsonl', []);
                $save('sources.jsonl', (function () {
                    for ($i = 0; $i < $this->races; $i++) {
                        for ($v = 0; $v < $this->versions; $v++) {
                            yield ['race_id' => 10 + $i, 'id' => 1 + $i * $this->versions + $v,
                                'source' => 'keirin_jp', 'request_parameters' => ['disp' => 'PJ0315', 'encp' => 'synthetic-'.$i],
                                'http_status' => 200, 'error_type' => null, 'utf8_conversion_succeeded' => true,
                                'raw_file_path' => 'detail.html', 'sha256' => $this->raw['sha256'], 'response_size' => $this->raw['bytes'],
                                'content_type' => 'text/html; charset=UTF-8', 'fetched_at' => '2026-07-01T00:00:00+09:00', 'parser_version' => 'SYNTHETIC'];
                        }
                    }
                })());

                return ['synthetic' => true];
            }
        };
        config(['filesystems.disks.local.root' => $root]);
        (new Exporter($ledger, $metadata))->export($root.'/ledger', $root.'/source');

        return $ledger;
    }
}
