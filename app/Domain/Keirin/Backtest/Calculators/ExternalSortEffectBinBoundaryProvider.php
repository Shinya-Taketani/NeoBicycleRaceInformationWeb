<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Support\BoundedProcessRunner;
use InvalidArgumentException;
use RuntimeException;

class ExternalSortEffectBinBoundaryProvider implements EffectBinBoundaryProvider
{
    public function __construct(
        private readonly ?string $temporaryDirectory = null,
        private readonly string $sortBinary = '/usr/bin/sort',
        private readonly BoundedProcessRunner $processRunner = new BoundedProcessRunner,
    ) {}

    public function build(iterable $trainingValues): array
    {
        $directory = $this->temporaryDirectory ?? sys_get_temp_dir();
        $inputPath = $this->temporaryPath($directory, 'bt02-bin-input-');
        try {
            $sortedPath = $this->temporaryPath($directory, 'bt02-bin-sorted-');
        } catch (\Throwable $throwable) {
            $this->remove([$inputPath]);
            throw $throwable;
        }
        $handle = fopen($inputPath, 'wb');
        if ($handle === false) {
            $this->remove([$inputPath, $sortedPath]);
            throw new RuntimeException('Could not open the BT-02 effect-bin input spool.');
        }

        try {
            $count = 0;
            $numeric = true;
            $categoryCounts = [];
            $trackCategories = true;
            foreach ($trainingValues as $value) {
                if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                    throw new InvalidArgumentException('Effect bin value type was invalid.');
                }
                $count++;
                if ($trackCategories) {
                    $category = $this->canonicalCategory($value);
                    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
                    if (count($categoryCounts) > 10) {
                        $trackCategories = false;
                        $categoryCounts = [];
                    }
                }
                if (! is_int($value) && ! is_float($value)) {
                    $numeric = false;

                    continue;
                }
                $this->write($handle, $this->canonicalNumber((float) $value)."\n");
            }
            if (! fflush($handle)) {
                throw new RuntimeException('Could not flush the BT-02 effect-bin input spool.');
            }
            fclose($handle);
            $handle = null;
            if ($count === 0) {
                throw new InvalidArgumentException('Effect bins require non-null training values.');
            }
            if ($trackCategories) {
                return $this->categoryBins($categoryCounts);
            }
            if (! $numeric) {
                throw new InvalidArgumentException('High-cardinality effect bins must be numeric.');
            }

            $sort = $this->processRunner->run(
                [$this->sortBinary, '-g', '-o', $sortedPath, $inputPath],
                ['LC_ALL' => 'C'],
                null,
                static function (string $chunk): void {
                    if ($chunk !== '') {
                        throw new RuntimeException('GNU sort unexpectedly wrote to stdout.');
                    }
                },
            );
            if ($sort->exitCode !== 0) {
                throw new RuntimeException('BT-02 external effect-bin sort failed: '.($sort->stderr ?: 'no stderr was provided'));
            }

            $boundaries = $this->type7Boundaries($sortedPath, $count);

            return $this->numericBins($sortedPath, $boundaries);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->remove([$inputPath, $sortedPath]);
        }
    }

    /** @param array<string, int> $counts @return list<EffectBinDto> */
    private function categoryBins(array $counts): array
    {
        uksort($counts, 'strnatcmp');
        $bins = [];
        $index = 1;
        foreach ($counts as $category => $count) {
            $bins[] = new EffectBinDto($index++, 'CATEGORY', null, null, (string) $category, $count);
        }

        return $bins;
    }

    /** @return list<float> */
    private function type7Boundaries(string $sortedPath, int $count): array
    {
        $ranks = [];
        $positions = [];
        for ($decile = 1; $decile <= 9; $decile++) {
            $probability = $decile / 10;
            $position = ($count - 1) * $probability;
            $lower = (int) floor($position);
            $upper = (int) ceil($position);
            $positions[] = [$position, $lower, $upper];
            $ranks[$lower] = null;
            $ranks[$upper] = null;
        }
        foreach ($this->sortedValues($sortedPath) as $index => $value) {
            if (array_key_exists($index, $ranks)) {
                $ranks[$index] = $value;
            }
        }
        $boundaries = [];
        foreach ($positions as [$position, $lower, $upper]) {
            if ($ranks[$lower] === null || $ranks[$upper] === null) {
                throw new RuntimeException('BT-02 sorted effect-bin ranks were incomplete.');
            }
            $boundaries[] = $lower === $upper
                ? $ranks[$lower]
                : $ranks[$lower] + ($position - $lower) * ($ranks[$upper] - $ranks[$lower]);
        }
        $boundaries = array_values(array_unique($boundaries, SORT_REGULAR));
        sort($boundaries, SORT_NUMERIC);

        return $boundaries;
    }

    /** @param list<float> $boundaries @return list<EffectBinDto> */
    private function numericBins(string $sortedPath, array $boundaries): array
    {
        $counts = array_fill(0, count($boundaries) + 1, 0);
        foreach ($this->sortedValues($sortedPath) as $value) {
            foreach ($boundaries as $index => $upper) {
                if ($value <= $upper) {
                    $counts[$index]++;

                    continue 2;
                }
            }
            $counts[count($boundaries)]++;
        }
        $bins = [];
        foreach ($counts as $index => $count) {
            $bins[] = new EffectBinDto(
                $index + 1,
                'NUMERIC_RANGE',
                $index === 0 ? null : $boundaries[$index - 1],
                $boundaries[$index] ?? null,
                null,
                $count,
            );
        }

        return $bins;
    }

    /** @return \Generator<int, float> */
    private function sortedValues(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the sorted BT-02 effect-bin spool.');
        }
        $previous = null;
        $index = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-02 sorted effect-bin value did not end with LF.');
                }
                $text = substr($line, 0, -1);
                if ($text === '' || ! is_numeric($text)) {
                    throw new RuntimeException('BT-02 sorted effect-bin value was invalid.');
                }
                $value = (float) $text;
                if (! is_finite($value) || ($previous !== null && $value < $previous)) {
                    throw new RuntimeException('BT-02 external effect-bin order was invalid.');
                }
                $previous = $value;
                yield $index++ => $value;
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully read the sorted BT-02 effect-bin spool.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function canonicalCategory(int|float|string $value): string
    {
        return is_float($value) ? $this->canonicalNumber($value) : (string) $value;
    }

    private function canonicalNumber(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Effect bin value was not finite.');
        }

        return sprintf('%.17g', $value);
    }

    /** @param resource $handle */
    private function write($handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the BT-02 effect-bin spool.');
            }
            $offset += $written;
        }
    }

    private function temporaryPath(string $directory, string $prefix): string
    {
        $path = tempnam($directory, $prefix);
        if ($path === false) {
            throw new RuntimeException('Could not create a BT-02 effect-bin temporary path.');
        }

        return $path;
    }

    /** @param list<string> $paths */
    private function remove(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
