<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Tests\Support\MemoryLimitedTestProcess;

if (ini_parse_quantity(ini_get('memory_limit')) !== 512 * 1024 * 1024) {
    throw new RuntimeException('The pollution probe must run separately under 512M.');
}
$case = $argv[2] ?? null;
if (! in_array($case, MemoryLimitedTestProcess::CASES, true)) {
    throw new RuntimeException('Unknown high-parent memory test identifier.');
}
$allocation = str_repeat('x', 130 * 1024 * 1024);
unset($allocation);
$peak = memory_get_peak_usage(true);
if ($peak <= MemoryLimitedTestProcess::LIMIT) {
    throw new RuntimeException('Parent peak was not raised above 128MiB.');
}
$result = MemoryLimitedTestProcess::run($case, $argv[1]);
echo json_encode(['parent_pid' => getmypid(), 'parent_peak' => $peak, 'parent_limit' => ini_get('memory_limit'),
    'child' => $result], JSON_THROW_ON_ERROR);
