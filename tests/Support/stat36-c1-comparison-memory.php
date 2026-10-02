<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Keirin\Backtest\Experiments\Stat36C1Comparison\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Tests\Support\Stat36C1ComparisonFixture;

$dir = $argv[1];
$input = $dir.'/input.jsonl';
$sidecar = $dir.'/sidecar.jsonl';
$races = static function (): Generator {
    foreach (Stat36C1ComparisonFixture::races(2024, 50000) as $race) {
        foreach ($race['entries'] as &$entry) {
            $entry['signals'][0] = str_repeat('x', 256);
        }
        unset($entry);
        yield $race;
    }
};
JsonlArtifact::write($input, $races());
JsonlArtifact::write($sidecar, (static function () use ($races): Generator {
    foreach ($races() as $race) {
        foreach ($race['entries'] as $entry) {
            yield Stat36C1ComparisonFixture::candidate($race, $entry);
        }
    }
})());
$source = ['paths' => [2024 => ['input' => $input, 'original' => $input, 'sidecar' => $sidecar]],
    'seals' => [$input => Files::identity($input), $sidecar => Files::identity($sidecar)],
    'expected_rows' => [2024 => 50000], 'expected_entries' => [2024 => 250000]];
$count = $entries = 0;
foreach ((new Dataset)->prediction($source, 2024) as $race) {
    $count++;
    $entries += count($race['entries']);
}
JsonlArtifact::json($dir.'/measurement.json', ['limit' => ini_get('memory_limit'), 'pid' => getmypid(),
    'races' => $count, 'entries' => $entries, 'input_bytes' => filesize($input), 'peak' => memory_get_peak_usage(true)]);
