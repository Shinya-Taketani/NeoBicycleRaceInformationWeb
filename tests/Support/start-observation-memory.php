<?php

declare(strict_types=1);

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Statistics\StartObservation\Builder;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use App\Domain\Keirin\Statistics\StartObservation\Parser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\StartObservationFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::swap(new class
{
    public function __call(string $name, array $arguments): never
    {
        throw new RuntimeException('No application DB access.');
    }
});
Http::fake(fn () => throw new RuntimeException('No HTTP access.'));
$root = $argv[1];
$pin = StartObservationFixture::bundle($root.'/source', 12000, 1, 9000);
$result = (new Builder(new Ledger($pin), new RawReader, new Parser))->build($root.'/source', $root.'/result');
JsonlArtifact::json($root.'/measurement.json', ['limit' => ini_get('memory_limit'), 'pid' => getmypid(),
    'peak' => memory_get_peak_usage(true), 'source_bytes' => filesize($root.'/source/database-inventory.jsonl'),
    'imports' => $result['years'][2024]['imports'], 'rows' => $result['years'][2024]['observation_rows']]);
