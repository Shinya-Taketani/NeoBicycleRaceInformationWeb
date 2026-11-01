<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use Throwable;

class Writer
{
    private array $streams = [];

    public function open(string $root): void
    {
        try {
            foreach (['race-coverage', 'candidate-inventory', 'projections'] as $name) {
                $this->streams[$name] = Files::create($root.'/'.$name.'.jsonl');
            }
        } catch (Throwable $e) {
            throw new OutputFailure('OUTPUT_OPEN_FAILED', previous: $e);
        }
    }

    public function write(string $name, array $value): void
    {
        try {
            Files::append($this->streams[$name], $value);
            if (! fflush($this->streams[$name])) {
                throw new OutputFailure('OUTPUT_FLUSH_FAILED');
            }
        } catch (Throwable $e) {
            throw new OutputFailure('OUTPUT_WRITE_FAILED:'.$name, previous: $e);
        }
    }

    public function close(): void
    {
        foreach ($this->streams as $stream) {
            fclose($stream);
        }
        $this->streams = [];
    }

    public function finish(string $root, array $summary, array $input): void
    {
        try {
            Files::write($root.'/coverage.json', $summary);
            $csv = Files::create($root.'/coverage.csv');
            try {
                fputcsv($csv, ['metric', 'count'], escape: '');
                foreach (['races', 'candidates', 'projected', 'excluded', 'failed', 'not_processed', 'saved', 'projection_rows_written', 'w45_projected', 'additional_projected'] as $key) {
                    if (fputcsv($csv, [$key, $summary[$key]], escape: '') === false) {
                        throw new OutputFailure('COVERAGE_WRITE_FAILED');
                    }
                }
            } finally {
                fclose($csv);
            }
            $files = [];
            foreach (glob($root.'/*') as $file) {
                $files[basename($file)] = Files::seal($file);
            }
            Files::write($root.'/manifest.json', ['contract' => Files::VERSION, 'input' => $input, 'files' => $files,
                'acceptance_policy' => 'SOURCE_IDENTITY_AND_RESULT_STRUCTURE_WITHOUT_METHOD_SEMANTICS']);
            $terminal = $summary['exit_code'] === 0 ? 'COMPLETE' : 'FAILED';
            Files::write($root.'/'.$terminal.'.pending', ['manifest' => Files::seal($root.'/manifest.json'), 'exit_code' => $summary['exit_code']]);
            if (file_exists($root.'/'.$terminal.'.json') || ! rename($root.'/'.$terminal.'.pending', $root.'/'.$terminal.'.json')) {
                throw new OutputFailure('TERMINAL_PUBLICATION_FAILED');
            }
        } catch (Throwable $e) {
            throw new OutputFailure('OUTPUT_FINALIZATION_FAILED', previous: $e);
        }
    }

    public function failure(string $root, array $summary): bool
    {
        try {
            Files::write($root.'/FAILED.json', ['reason' => 'OUTPUT_FAILURE', 'summary' => $summary, 'exit_code' => 1]);

            return true;
        } catch (Throwable) {
            // One diagnostic attempt only; preserve the original failure and partial files.
            return false;
        }
    }
}
