<?php

declare(strict_types=1);
use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Builder;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Parser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\StartCountFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (ini_get('memory_limit') !== '128M' || app()->environment() !== 'testing') {
    throw new RuntimeException('Isolated 128M testing process required.');
}
$root = $argv[1];
$ledger = StartCountFixture::bundle($root, 1500, 2, 72000);
DB::swap(new class
{
    public function __call(string $method, array $args): never
    {
        throw new RuntimeException('DB forbidden');
    }
});
Http::preventStrayRequests();
$result = (new Builder($ledger,
    new Parser,
    new RawReader))->build($root.'/source', $root.'/build');
file_put_contents($root.'/measurement.json', json_encode(['limit' => ini_get('memory_limit'),
    'peak_memory_bytes' => memory_get_peak_usage(true), 'rows' => $result['years'][2024]['snapshot_rows'],
    'ledger_bytes' => filesize($root.'/ledger/database-inventory.jsonl')], JSON_THROW_ON_ERROR));
