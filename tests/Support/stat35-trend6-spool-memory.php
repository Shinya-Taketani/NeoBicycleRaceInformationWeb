<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\RetainedBinBoundaryProvider;
use Tests\Support\CompositionResultTemporaryDirectory;

umask(0077);
$temporary = CompositionResultTemporaryDirectory::create();
$reason = 'PROCESS_FAILURE';
try {
    if (ini_parse_quantity(ini_get('memory_limit')) !== 128 * 1024 * 1024) {
        throw new RuntimeException('The actual child must have a 128M memory limit.');
    }
    $values = static function (): Generator {
        for ($i = 100000; $i >= 0; $i--) {
            yield $i / 13.0;
        }
    };
    $bins = (new RetainedBinBoundaryProvider($temporary->path()))->build($values());
    $support = array_sum(array_column($bins, 'trainingSampleCount'));
    if ($support !== 100001) {
        throw new RuntimeException('Streamed training support mismatch.');
    }
    $reason = 'PROCESS_COMPLETE';
} finally {
    $retirement = $temporary->retire($temporary->path(), $reason);
}
$handle = fopen($argv[1].'/measurement.json', 'xb');
if ($handle === false) {
    throw new RuntimeException('Cannot exclusively record synthetic memory evidence.');
}
fwrite($handle, json_encode(['memory_limit_bytes' => ini_parse_quantity(ini_get('memory_limit')),
    'peak_memory_bytes' => memory_get_peak_usage(true), 'training_support' => $support,
    'retirement' => $retirement], JSON_THROW_ON_ERROR)."\n");
fclose($handle);
