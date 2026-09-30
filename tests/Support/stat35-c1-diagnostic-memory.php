<?php

declare(strict_types=1);

use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Builder;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Stat35C1DiagnosticFixture;

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
$b = Stat35C1DiagnosticFixture::make($root.'/fixture', 12000);
$app->instance(Sources::class, $b['sources']);
$result = app(Builder::class)->build($b['compare'], $b['input'], $b['baseline'], $root.'/result');
JsonlArtifact::json($root.'/measurement.json', ['limit' => ini_get('memory_limit'), 'pid' => getmypid(),
    'peak' => memory_get_peak_usage(true), 'races' => array_sum(array_column($result['cohort'], 'races')),
    'entries' => array_sum(array_column($result['cohort'], 'entries')),
    'stream_bytes' => array_sum(array_column(Files::json($root.'/result/manifest.json')['source']['seals'], 'bytes'))]);
