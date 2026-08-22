<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt03eBinRuleDto;
use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use RuntimeException;
use Tests\TestCase;

class Bt03eArtifactWriterTest extends TestCase
{
    public function test_json_and_csv_preserve_full_rule_identity_and_integer_points(): void
    {
        $directory = sys_get_temp_dir().'/bt03e-artifact-test-'.bin2hex(random_bytes(6));
        $weights = array_fill_keys(Bt03eContract::STAT_CODES, 0);
        $weights['STAT-07'] = 10;
        $candidate = new Bt03eCandidateDto(20, $weights);
        $rule = new Bt03eBinRuleDto(
            'STAT-07', 1, 'TRAINING_BIN', 'NUMERIC_RANGE', null, 0.5, null,
            71, str_repeat('a', 64), 100, -2,
        );
        $summary = ['point_rules' => [[...$rule->canonical(), 'stat_weight' => 10, 'final_points' => -20, 'source_fold' => 'WF_2023']]];

        try {
            $paths = (new Bt03eArtifactWriter)->write($directory, $summary, [$rule], $candidate);
            $json = json_decode((string) file_get_contents($paths['json']), true, flags: JSON_THROW_ON_ERROR);
            $csv = (string) file_get_contents($paths['csv']);

            $this->assertDirectoryExists($paths['bundle_directory']);
            $this->assertFileExists($paths['json']);
            $this->assertFileExists($paths['csv']);
            $this->assertSame('TRAINING_BIN', $json['point_rules'][0]['bin_origin']);
            $this->assertSame(-20, $json['point_rules'][0]['final_points']);
            $this->assertStringContainsString('bin_origin', $csv);
            $this->assertStringContainsString('STAT-01,,BASE_RANK,BASE_RANK', $csv);
            $this->assertStringContainsString('STAT-07,1,TRAINING_BIN,NUMERIC_RANGE', $csv);
            $this->assertStringContainsString(',-2,10,-20,WF_2023,71,', $csv);
        } finally {
            $this->removeTree($directory);
        }
    }

    public function test_json_failure_leaves_no_final_or_temporary_bundle(): void
    {
        $this->assertFailureCleansBundle('json');
    }

    public function test_rename_failure_leaves_no_final_or_temporary_bundle(): void
    {
        $this->assertFailureCleansBundle('publish');
    }

    public function test_every_csv_row_failure_is_detected_and_cleans_the_bundle(): void
    {
        foreach ([1, 2, 3] as $rowNumber) {
            $this->assertFailureCleansBundle('csv', $rowNumber);
        }
    }

    public function test_same_second_publications_use_distinct_bundle_names(): void
    {
        $directory = $this->directory();
        try {
            [$summary, $rules, $candidate] = $this->artifactInput();
            $writer = new Bt03eArtifactWriter;
            $first = $writer->write($directory, $summary, $rules, $candidate);
            $second = $writer->write($directory, $summary, $rules, $candidate);

            $this->assertNotSame($first['bundle_directory'], $second['bundle_directory']);
            $this->assertDirectoryExists($first['bundle_directory']);
            $this->assertDirectoryExists($second['bundle_directory']);
        } finally {
            $this->removeTree($directory);
        }
    }

    public function test_existing_final_bundle_is_never_overwritten(): void
    {
        $directory = $this->directory();
        $filesystem = new Bt03eArtifactFilesystemFake('fixed-bundle');
        $final = $directory.'/fixed-bundle';
        try {
            mkdir($final, 0775, true);
            file_put_contents($final.'/sentinel', 'keep');
            [$summary, $rules, $candidate] = $this->artifactInput();

            try {
                (new Bt03eArtifactWriter($filesystem))->write($directory, $summary, $rules, $candidate);
                $this->fail('An existing final bundle must never be overwritten.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('overwrite was refused', $exception->getMessage());
            }
            $this->assertSame('keep', file_get_contents($final.'/sentinel'));
            $this->assertSame([], glob($directory.'/.fixed-bundle.tmp') ?: []);
        } finally {
            $this->removeTree($directory);
        }
    }

    private function assertFailureCleansBundle(string $failure, ?int $csvRow = null): void
    {
        $directory = $this->directory();
        $name = 'failed-bundle-'.$failure.($csvRow ?? '');
        try {
            [$summary, $rules, $candidate] = $this->artifactInput();
            $filesystem = new Bt03eArtifactFilesystemFake($name, $failure, $csvRow);

            try {
                (new Bt03eArtifactWriter($filesystem))->write($directory, $summary, $rules, $candidate);
                $this->fail('The injected artifact failure must be reported.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('injected', $exception->getMessage());
            }
            $this->assertDirectoryDoesNotExist($directory.'/'.$name);
            $this->assertDirectoryDoesNotExist($directory.'/.'.$name.'.tmp');
            $this->assertSame([], glob($directory.'/*') ?: []);
        } finally {
            $this->removeTree($directory);
        }
    }

    /** @return array{array<string, mixed>, list<Bt03eBinRuleDto>, Bt03eCandidateDto} */
    private function artifactInput(): array
    {
        $weights = array_fill_keys(Bt03eContract::STAT_CODES, 0);
        $weights['STAT-07'] = 10;
        $candidate = new Bt03eCandidateDto(20, $weights);
        $rule = new Bt03eBinRuleDto(
            'STAT-07', 1, 'TRAINING_BIN', 'NUMERIC_RANGE', null, 0.5, null,
            71, str_repeat('a', 64), 100, -2,
        );

        return [
            ['point_rules' => [[...$rule->canonical(), 'stat_weight' => 10, 'final_points' => -20, 'source_fold' => 'WF_2023']]],
            [$rule],
            $candidate,
        ];
    }

    private function directory(): string
    {
        return sys_get_temp_dir().'/bt03e-artifact-test-'.bin2hex(random_bytes(6));
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $childPath = $path.'/'.$child;
            if (is_dir($childPath)) {
                $this->removeTree($childPath);
            } else {
                unlink($childPath);
            }
        }
        rmdir($path);
    }
}

class Bt03eArtifactFilesystemFake extends Bt03eArtifactFilesystem
{
    private int $csvRow = 0;

    public function __construct(
        private readonly string $name,
        private readonly ?string $failure = null,
        private readonly ?int $failedCsvRow = null,
    ) {}

    public function bundleName(): string
    {
        return $this->name;
    }

    public function writeExact(string $path, string $contents): void
    {
        if ($this->failure === 'json') {
            throw new RuntimeException('injected JSON failure');
        }
        parent::writeExact($path, $contents);
    }

    public function writeCsvRow($handle, array $row): void
    {
        $this->csvRow++;
        if ($this->failure === 'csv' && $this->csvRow === $this->failedCsvRow) {
            throw new RuntimeException('injected CSV failure');
        }
        parent::writeCsvRow($handle, $row);
    }

    public function publish(string $temporaryDirectory, string $finalDirectory): void
    {
        if ($this->failure === 'publish') {
            throw new RuntimeException('injected publish failure');
        }
        parent::publish($temporaryDirectory, $finalDirectory);
    }
}
