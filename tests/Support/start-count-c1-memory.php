<?php

declare(strict_types=1);

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Builder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\StartCountC1Fixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (ini_get('memory_limit') !== '128M' || app()->environment() !== 'testing') {
    throw new RuntimeException('Isolated testing process with 128M required.');
}
DB::swap(new class
{
    public function __call(string $name, array $arguments): never
    {
        throw new RuntimeException('DB forbidden');
    }
});
Http::preventStrayRequests();
$root = $argv[1];
$sources = StartCountC1Fixture::bundle($root, races: 500, padding: 12000);
$result = (new Builder($sources))->build($root.'/build');
$bytes = 0;
foreach ([2022, 2023, 2024, 2025] as $year) {
    $bytes += filesize($root.'/c1/c1-'.$year.'.jsonl');
}
file_put_contents($root.'/measurement.json', Files::canonical(['limit' => ini_get('memory_limit'),
    'peak_memory_bytes' => memory_get_peak_usage(true), 'source_bytes' => $bytes,
    'candidates' => $result['coverage']['total']['entries'], 'source_rows' => $result['coverage']['total']['source_rows']])."\n");
