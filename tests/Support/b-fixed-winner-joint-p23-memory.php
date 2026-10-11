<?php

declare(strict_types=1);

use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Reader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\BFixedWinnerJointP23Fixture;
use Tests\Support\C1MarginalP23Fixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = $argv[1];
$n = 18000;
$template = BFixedWinnerJointP23Fixture::row(C1MarginalP23Fixture::race());
$template['provenance'] = ['artificial_memory_fixture' => true];
$source = ['paths' => [2024 => ['input' => $root.'/input.jsonl', 'b_predictions' => $root.'/B.jsonl', 'b_decisions' => $root.'/B-decisions.jsonl']],
    'expected_rows' => [2024 => $n], 'expected_entries' => [2024 => $n * 7], 'seals' => []];
JsonlArtifact::write($source['paths'][2024]['input'], (function () use ($n) {
    for ($i = 0; $i < $n; $i++) {
        yield C1MarginalP23Fixture::race(2024, $i + 1);
    }
})());
JsonlArtifact::write($source['paths'][2024]['b_predictions'], (function () use ($n, $template) {
    for ($i = 0; $i < $n; $i++) {
        $row = $template;
        $input = C1MarginalP23Fixture::race(2024, $i + 1);
        $row['race_id'] = $row['probabilities']['race_id'] = $row['candidate']['race_id'] = $row['baseline']['race_id'] = $i + 1;
        $row['cohort'] = array_map(fn ($e) => [$e['id'], $e['bike']], $input['entries']);
        $row['source_row'] = $i + 1;
        foreach ($row['probabilities']['entries'] as $j => &$entry) {
            $entry['id'] = $input['entries'][$j]['id'];
        }
        unset($entry);
        yield $row;
    }
})());
JsonlArtifact::write($source['paths'][2024]['b_decisions'], (function () use ($source) {
    foreach (JsonlArtifact::read($source['paths'][2024]['b_predictions']) as $row) {
        unset($row['probabilities']);
        yield $row;
    }
})());
$source['seals'][$source['paths'][2024]['input']] = Files::identity($source['paths'][2024]['input']);
$seen = Reader::identities($root.'/seen.sqlite');
$audit = [];
JsonlArtifact::write($root.'/C.jsonl', app(Reader::class)->rows($source, 2024, $seen, $audit));
$seen->commit();
JsonlArtifact::json($root.'/measurement.json', ['limit' => ini_get('memory_limit'), 'pid' => getmypid(),
    'input_bytes' => filesize($source['paths'][2024]['b_predictions']), 'peak' => memory_get_peak_usage(true)] + $audit);
