<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Keirin\Statistics\Calculators\Stat20Calculator;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use Tests\Support\Stat20Fixture;

$calculator = new Stat20Calculator(new DeterministicJsonHasher);
$bytes = $entries = 0;
$hash = hash_init('sha256');
for ($raceId = 1; $raceId <= 6000; $raceId++) {
    $race = Stat20Fixture::race(7, $raceId);
    $bytes += strlen(json_encode(array_map(fn ($s) => $s->hashPayload(), $race->sources), JSON_THROW_ON_ERROR));
    $results = $calculator->calculate($race);
    $entries += count($results);
    foreach ($results as $r) {
        hash_update($hash, $r->inputHash);
    }
    unset($race, $results);
}
echo json_encode(['memory_limit' => ini_get('memory_limit'), 'races' => 6000, 'entries' => $entries,
    'input_bytes' => $bytes, 'peak_memory_bytes' => memory_get_peak_usage(true), 'hash' => hash_final($hash)], JSON_THROW_ON_ERROR).PHP_EOL;
