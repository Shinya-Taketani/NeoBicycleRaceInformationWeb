<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt02PairedMetricEvaluationDto;
use App\Domain\Keirin\Backtest\Support\BoundedProcessRunner;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionSpool;
use RuntimeException;

class PairedRaceClusterMetricEvaluator
{
    private const AUC_BATCH_SIZE = 64;

    public function __construct(
        private readonly RaceClusterBootstrap $bootstrap,
        private readonly Type7Quantile $quantile,
        private readonly ?string $temporaryDirectory = null,
        private readonly string $sortBinary = '/usr/bin/sort',
        private readonly BoundedProcessRunner $processRunner = new BoundedProcessRunner,
    ) {}

    public function evaluate(
        Bt02PredictionSpool $spool,
        int $iterations = RaceClusterBootstrap::ITERATIONS,
        int $seed = RaceClusterBootstrap::SEED,
    ): Bt02PairedMetricEvaluationDto {
        if ($iterations < 1) {
            throw new RuntimeException('BT-02 paired bootstrap iterations must be positive.');
        }
        $paths = $this->paths();
        $baselineHandle = fopen($paths['baseline_input'], 'wb');
        $incrementalHandle = fopen($paths['incremental_input'], 'wb');
        if ($baselineHandle === false || $incrementalHandle === false) {
            $this->close($baselineHandle);
            $this->close($incrementalHandle);
            $this->remove($paths);
            throw new RuntimeException('Could not create BT-02 AUC sort inputs.');
        }

        try {
            $raceIds = $entryCounts = $baselineLogLoss = $incrementalLogLoss = $baselineBrier = $incrementalBrier = [];
            $lastRaceId = null;
            $raceIndex = -1;
            foreach ($spool->rows() as $row) {
                if ($lastRaceId !== $row['race_id']) {
                    $raceIndex++;
                    $raceIds[] = $row['race_id'];
                    $entryCounts[] = 0;
                    $baselineLogLoss[] = $incrementalLogLoss[] = $baselineBrier[] = $incrementalBrier[] = 0.0;
                    $lastRaceId = $row['race_id'];
                }
                $entryCounts[$raceIndex]++;
                $baselineLogLoss[$raceIndex] += $this->logLossContribution($row['baseline'], $row['label']);
                $incrementalLogLoss[$raceIndex] += $this->logLossContribution($row['incremental'], $row['label']);
                $baselineBrier[$raceIndex] += ($row['baseline'] - $row['label']) ** 2;
                $incrementalBrier[$raceIndex] += ($row['incremental'] - $row['label']) ** 2;
                $this->write($baselineHandle, sprintf('%.17g', $row['baseline'])."\t{$raceIndex}\t{$row['label']}\n");
                $this->write($incrementalHandle, sprintf('%.17g', $row['incremental'])."\t{$raceIndex}\t{$row['label']}\n");
            }
            $this->flush($baselineHandle, 'baseline');
            $this->flush($incrementalHandle, 'incremental');
            fclose($baselineHandle);
            fclose($incrementalHandle);
            $baselineHandle = $incrementalHandle = null;
            if ($raceIds === []) {
                throw new RuntimeException('BT-02 paired metric evaluation had no races.');
            }
            $this->sort($paths['baseline_input'], $paths['baseline_sorted']);
            $this->sort($paths['incremental_input'], $paths['incremental_sorted']);

            $rowCount = array_sum($entryCounts);
            $unitWeights = [array_fill(0, count($raceIds), 1)];
            $pointBaselineAuc = $this->weightedAucBatch($paths['baseline_sorted'], $unitWeights)[0];
            $pointIncrementalAuc = $this->weightedAucBatch($paths['incremental_sorted'], $unitWeights)[0];
            $point = [
                'AUC' => [$pointBaselineAuc, $pointIncrementalAuc],
                'LOG_LOSS' => [array_sum($baselineLogLoss) / $rowCount, array_sum($incrementalLogLoss) / $rowCount],
                'BRIER' => [array_sum($baselineBrier) / $rowCount, array_sum($incrementalBrier) / $rowCount],
            ];
            $deltas = ['AUC' => [], 'LOG_LOSS' => [], 'BRIER' => []];
            $weightBatch = [];
            $replicates = 0;
            foreach ($this->bootstrap->resampleIndexes(count($raceIds), $iterations, $seed) as $indexes) {
                $weights = array_fill(0, count($raceIds), 0);
                foreach ($indexes as $index) {
                    $weights[$index]++;
                }
                $weightBatch[] = $weights;
                $replicates++;
                if (count($weightBatch) === self::AUC_BATCH_SIZE) {
                    $this->consumeBatch($paths, $weightBatch, $entryCounts, $baselineLogLoss, $incrementalLogLoss, $baselineBrier, $incrementalBrier, $deltas);
                    $weightBatch = [];
                }
            }
            if ($weightBatch !== []) {
                $this->consumeBatch($paths, $weightBatch, $entryCounts, $baselineLogLoss, $incrementalLogLoss, $baselineBrier, $incrementalBrier, $deltas);
            }
            if ($replicates !== $iterations) {
                throw new RuntimeException('BT-02 paired bootstrap replicate count was incomplete.');
            }

            $metrics = [];
            foreach ($point as $metric => [$baseline, $incremental]) {
                $delta = $baseline !== null && $incremental !== null ? $incremental - $baseline : null;
                $samples = $deltas[$metric];
                $metrics[$metric] = [
                    'baseline' => $baseline,
                    'incremental' => $incremental,
                    'delta' => $delta,
                    'ci_lower' => $samples === [] ? null : $this->quantile->calculate($samples, 0.025),
                    'ci_upper' => $samples === [] ? null : $this->quantile->calculate($samples, 0.975),
                ];
            }
            $temporaryBytes = $spool->metadata()->byteCount;
            foreach ($paths as $path) {
                $temporaryBytes += is_file($path) ? (int) filesize($path) : 0;
            }

            return new Bt02PairedMetricEvaluationDto($metrics, $raceIds, $rowCount, $replicates, $temporaryBytes);
        } finally {
            $this->close($baselineHandle);
            $this->close($incrementalHandle);
            $this->remove($paths);
        }
    }

    /**
     * @param  array{baseline_input: string, incremental_input: string, baseline_sorted: string, incremental_sorted: string}  $paths
     * @param  list<list<int>>  $weightBatch
     * @param  list<int>  $entryCounts
     * @param  list<float>  $baselineLogLoss
     * @param  list<float>  $incrementalLogLoss
     * @param  list<float>  $baselineBrier
     * @param  list<float>  $incrementalBrier
     * @param  array<string, list<float>>  $deltas
     */
    private function consumeBatch(
        array $paths,
        array $weightBatch,
        array $entryCounts,
        array $baselineLogLoss,
        array $incrementalLogLoss,
        array $baselineBrier,
        array $incrementalBrier,
        array &$deltas,
    ): void {
        $baselineAuc = $this->weightedAucBatch($paths['baseline_sorted'], $weightBatch);
        $incrementalAuc = $this->weightedAucBatch($paths['incremental_sorted'], $weightBatch);
        foreach ($weightBatch as $replicate => $weights) {
            $rows = 0;
            $baseLog = $incrementalLog = $baseBrier = $incrementalBrierSum = 0.0;
            foreach ($weights as $race => $weight) {
                if ($weight === 0) {
                    continue;
                }
                $rows += $weight * $entryCounts[$race];
                $baseLog += $weight * $baselineLogLoss[$race];
                $incrementalLog += $weight * $incrementalLogLoss[$race];
                $baseBrier += $weight * $baselineBrier[$race];
                $incrementalBrierSum += $weight * $incrementalBrier[$race];
            }
            if ($rows < 1) {
                throw new RuntimeException('BT-02 paired bootstrap sample was empty.');
            }
            if ($baselineAuc[$replicate] !== null && $incrementalAuc[$replicate] !== null) {
                $deltas['AUC'][] = $incrementalAuc[$replicate] - $baselineAuc[$replicate];
            }
            $deltas['LOG_LOSS'][] = ($incrementalLog - $baseLog) / $rows;
            $deltas['BRIER'][] = ($incrementalBrierSum - $baseBrier) / $rows;
        }
    }

    /** @param list<list<int>> $weightBatch @return list<?float> */
    private function weightedAucBatch(string $path, array $weightBatch): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open sorted BT-02 AUC input.');
        }
        $size = count($weightBatch);
        $numerator = $cumulativeNegatives = $groupPositives = $groupNegatives = $totalPositives = array_fill(0, $size, 0.0);
        $currentScore = $previousScore = null;
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Sorted BT-02 AUC row did not end with LF.');
                }
                $parts = explode("\t", substr($line, 0, -1));
                if (count($parts) !== 3 || ! is_numeric($parts[0]) || ! ctype_digit($parts[1]) || ! in_array($parts[2], ['0', '1'], true)) {
                    throw new RuntimeException('Sorted BT-02 AUC row was invalid.');
                }
                $score = (float) $parts[0];
                $raceIndex = (int) $parts[1];
                $label = (int) $parts[2];
                if (! is_finite($score) || ($previousScore !== null && $score < $previousScore)) {
                    throw new RuntimeException('Sorted BT-02 AUC score order was invalid.');
                }
                if ($currentScore !== null && $score !== $currentScore) {
                    $this->flushAucGroup($numerator, $cumulativeNegatives, $groupPositives, $groupNegatives, $totalPositives);
                }
                $currentScore = $previousScore = $score;
                foreach ($weightBatch as $replicate => $weights) {
                    if (! array_key_exists($raceIndex, $weights)) {
                        throw new RuntimeException('BT-02 AUC race index was invalid.');
                    }
                    $weight = $weights[$raceIndex];
                    if ($label === 1) {
                        $groupPositives[$replicate] += $weight;
                    } else {
                        $groupNegatives[$replicate] += $weight;
                    }
                }
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully read sorted BT-02 AUC input.');
            }
            if ($currentScore !== null) {
                $this->flushAucGroup($numerator, $cumulativeNegatives, $groupPositives, $groupNegatives, $totalPositives);
            }
        } finally {
            fclose($handle);
        }
        $auc = [];
        foreach ($numerator as $replicate => $value) {
            $positives = $totalPositives[$replicate];
            $negatives = $cumulativeNegatives[$replicate];
            $auc[] = $positives === 0.0 || $negatives === 0.0 ? null : $value / ($positives * $negatives);
        }

        return $auc;
    }

    /** @param list<float> $numerator @param list<float> $cumulativeNegatives @param list<float> $groupPositives @param list<float> $groupNegatives @param list<float> $totalPositives */
    private function flushAucGroup(array &$numerator, array &$cumulativeNegatives, array &$groupPositives, array &$groupNegatives, array &$totalPositives): void
    {
        foreach ($numerator as $replicate => $_) {
            $numerator[$replicate] += $groupPositives[$replicate] * ($cumulativeNegatives[$replicate] + 0.5 * $groupNegatives[$replicate]);
            $cumulativeNegatives[$replicate] += $groupNegatives[$replicate];
            $totalPositives[$replicate] += $groupPositives[$replicate];
            $groupPositives[$replicate] = $groupNegatives[$replicate] = 0.0;
        }
    }

    private function logLossContribution(float $probability, int $label): float
    {
        $probability = min(max($probability, BinaryMetricCalculator::LOG_LOSS_EPSILON), 1.0 - BinaryMetricCalculator::LOG_LOSS_EPSILON);

        return -($label * log($probability) + (1 - $label) * log(1.0 - $probability));
    }

    private function sort(string $input, string $output): void
    {
        $result = $this->processRunner->run(
            [$this->sortBinary, '-g', '-k1,1', '-k2,2n', '-k3,3n', '-o', $output, $input],
            ['LC_ALL' => 'C'],
            null,
            static function (string $chunk): void {
                if ($chunk !== '') {
                    throw new RuntimeException('GNU sort unexpectedly wrote BT-02 AUC output to stdout.');
                }
            },
        );
        if ($result->exitCode !== 0) {
            throw new RuntimeException('BT-02 external AUC sort failed: '.($result->stderr ?: 'no stderr was provided'));
        }
    }

    /** @return array{baseline_input: string, incremental_input: string, baseline_sorted: string, incremental_sorted: string} */
    private function paths(): array
    {
        $directory = $this->temporaryDirectory ?? sys_get_temp_dir();
        $paths = [];
        try {
            foreach (['baseline_input', 'incremental_input', 'baseline_sorted', 'incremental_sorted'] as $name) {
                $path = tempnam($directory, 'bt02-bootstrap-');
                if ($path === false) {
                    throw new RuntimeException('Could not create a BT-02 bootstrap temporary path.');
                }
                $paths[$name] = $path;
            }
        } catch (\Throwable $throwable) {
            $this->remove($paths);
            throw $throwable;
        }

        /** @var array{baseline_input: string, incremental_input: string, baseline_sorted: string, incremental_sorted: string} $paths */
        return $paths;
    }

    /** @param resource $handle */
    private function flush($handle, string $role): void
    {
        if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
            throw new RuntimeException("Could not flush the BT-02 {$role} AUC input.");
        }
    }

    /** @param resource $handle */
    private function write($handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the BT-02 AUC input.');
            }
            $offset += $written;
        }
    }

    /** @param resource|false|null $handle */
    private function close($handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    /** @param array<string, string> $paths */
    private function remove(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
