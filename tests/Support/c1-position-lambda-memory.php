<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Tests\Support\Stat39C1FieldBikeFixture;

$dir = $argv[1];
$input = $dir.'/input.jsonl';
$races = static function (): Generator {
    foreach (Stat39C1FieldBikeFixture::races(2024, 50000) as $race) {
        foreach ($race['entries'] as &$entry) {
            $entry['signals'][0] = str_repeat('x', 256);
        }
        unset($entry);
        yield $race;
    }
};
JsonlArtifact::write($input, $races());
$source = ['paths' => [2024 => ['input' => $input, 'original' => $input]],
    'seals' => [$input => Files::identity($input)],
    'expected_rows' => [2024 => 50000], 'expected_entries' => [2024 => 250000]];
$count = $entries = 0;
foreach ((new Dataset)->prediction($source, 2024) as $race) {
    $count++;
    $entries += count($race['entries']);
}
JsonlArtifact::json($dir.'/measurement.json', ['limit' => ini_get('memory_limit'), 'pid' => getmypid(),
    'races' => $count, 'entries' => $entries, 'input_bytes' => filesize($input), 'peak' => memory_get_peak_usage(true)]);
