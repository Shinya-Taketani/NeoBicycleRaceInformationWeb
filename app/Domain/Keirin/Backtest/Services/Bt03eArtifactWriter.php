<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt03eBinRuleDto;
use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use RuntimeException;
use Throwable;

class Bt03eArtifactWriter
{
    public function __construct(
        private readonly Bt03eArtifactFilesystem $filesystem = new Bt03eArtifactFilesystem,
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<Bt03eBinRuleDto>  $rules
     * @return array{bundle_directory: string, json: string, csv: string}
     */
    public function write(string $directory, array $summary, array $rules, Bt03eCandidateDto $candidate): array
    {
        $root = rtrim($directory, '/') ?: '/';
        $this->filesystem->ensureDirectory($root);
        $bundleName = $this->filesystem->bundleName();
        $temporaryDirectory = $root.'/.'.$bundleName.'.tmp';
        $finalDirectory = $root.'/'.$bundleName;
        if ($this->filesystem->exists($temporaryDirectory) || $this->filesystem->exists($finalDirectory)) {
            throw new RuntimeException('BT-03E artifact bundle already existed; overwrite was refused.');
        }

        $this->filesystem->createDirectory($temporaryDirectory);
        $temporaryJson = $temporaryDirectory.'/result.json';
        $temporaryCsv = $temporaryDirectory.'/point-rules.csv';
        $handle = null;
        try {
            $json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            $this->filesystem->writeExact($temporaryJson, $json);
            if ($this->filesystem->size($temporaryJson) !== strlen($json)) {
                throw new RuntimeException('BT-03E JSON artifact size verification failed.');
            }

            $handle = $this->filesystem->openExclusive($temporaryCsv);
            $this->filesystem->writeCsvRow($handle, ['stat_code', 'bin_index', 'bin_origin', 'bin_kind', 'lower_bound', 'upper_bound', 'category_value', 'direction_strength', 'stat_weight', 'final_points', 'source_fold', 'source_effect_bin_id', 'source_boundaries_hash']);
            $this->filesystem->writeCsvRow($handle, ['STAT-01', '', 'BASE_RANK', 'BASE_RANK', '', '', '', '', '', $candidate->baseStep, Bt03eContract::SOURCE_FOLD, '', '']);
            foreach ($rules as $rule) {
                $weight = $candidate->weights[$rule->statCode];
                $this->filesystem->writeCsvRow($handle, [
                    $rule->statCode, $rule->binIndex, $rule->binOrigin, $rule->binKind, $rule->lowerBound,
                    $rule->upperBound, $rule->categoryValue, $rule->directionStrength,
                    $weight, $rule->directionStrength * $weight, Bt03eContract::SOURCE_FOLD,
                    $rule->sourceEffectBinId, $rule->sourceBoundariesHash,
                ]);
            }
            $this->filesystem->flushAndSync($handle);
            $closingHandle = $handle;
            $handle = null;
            $this->filesystem->close($closingHandle);
            if ($this->filesystem->size($temporaryCsv) <= 0) {
                throw new RuntimeException('BT-03E CSV artifact was empty.');
            }

            $this->filesystem->publish($temporaryDirectory, $finalDirectory);
        } catch (Throwable $throwable) {
            if (is_resource($handle)) {
                try {
                    $this->filesystem->close($handle);
                } catch (Throwable) {
                    // Preserve the publication failure and continue cleanup.
                }
            }
            try {
                $this->filesystem->removeDirectory($temporaryDirectory);
            } catch (Throwable) {
                // Preserve the publication failure.
            }
            throw $throwable;
        }

        return [
            'bundle_directory' => $finalDirectory,
            'json' => $finalDirectory.'/result.json',
            'csv' => $finalDirectory.'/point-rules.csv',
        ];
    }
}
