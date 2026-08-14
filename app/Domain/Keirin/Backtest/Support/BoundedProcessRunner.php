<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\BoundedProcessResultDto;
use RuntimeException;

class BoundedProcessRunner
{
    private const STDERR_LIMIT = 65536;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @param  callable(string): void  $stdoutConsumer
     */
    public function run(array $command, array $environment, ?string $stdin, callable $stdoutConsumer): BoundedProcessResultDto
    {
        if ($command === []) {
            throw new RuntimeException('Bounded process command must not be empty.');
        }
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $environment, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the bounded subprocess.');
        }

        try {
            $this->writeStdin($pipes[0], $stdin ?? '');
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stderr = '';
            $stdoutBytes = 0;
            $exitCode = null;
            $open = [1 => true, 2 => true];
            while ($open !== []) {
                $read = [];
                foreach (array_keys($open) as $index) {
                    $read[] = $pipes[$index];
                }
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 1);
                if ($selected === false) {
                    throw new RuntimeException('Could not read the bounded subprocess pipes.');
                }
                foreach ($read as $stream) {
                    $index = $stream === $pipes[1] ? 1 : 2;
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('Could not read a bounded subprocess stream.');
                    }
                    if ($chunk !== '') {
                        if ($index === 1) {
                            $stdoutBytes += strlen($chunk);
                            $stdoutConsumer($chunk);
                        } elseif (strlen($stderr) < self::STDERR_LIMIT) {
                            $stderr .= substr($chunk, 0, self::STDERR_LIMIT - strlen($stderr));
                        }
                    }
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$index]);
                    }
                }
                $status = proc_get_status($process);
                if (! $status['running'] && $status['exitcode'] >= 0) {
                    $exitCode = (int) $status['exitcode'];
                }
            }
            $closedExitCode = proc_close($process);
            $process = null;
            $exitCode ??= $closedExitCode;

            return new BoundedProcessResultDto($exitCode, trim($stderr), $stdoutBytes);
        } finally {
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /** @param resource $stream */
    private function writeStdin($stream, string $stdin): void
    {
        $offset = 0;
        while ($offset < strlen($stdin)) {
            $written = fwrite($stream, substr($stdin, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the bounded subprocess input.');
            }
            $offset += $written;
        }
    }
}
