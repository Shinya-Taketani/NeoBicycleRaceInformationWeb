<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = getenv('STAT38_MEMORY_DIRECTORY');
if (ini_parse_quantity(ini_get('memory_limit')) !== 128 * 1024 * 1024
    || ! is_string($directory) || ! str_starts_with($directory, '/') || is_link($directory)
    || realpath($directory) !== $directory || ! is_dir($directory)) {
    throw new RuntimeException('Invalid STAT-38 independent memory verification environment.');
}

register_shutdown_function(static function () use ($directory): void {
    $bytes = json_encode(['child_pid' => getmypid(), 'parent_pid' => (int) getenv('STAT38_MEMORY_PARENT_PID'),
        'memory_limit' => ini_get('memory_limit'), 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n";
    $stream = fopen($directory.'/measurement.json', 'xb');
    if ($stream === false || fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
        throw new RuntimeException('Cannot retain STAT-38 child memory measurement.');
    }
    fclose($stream);
});
