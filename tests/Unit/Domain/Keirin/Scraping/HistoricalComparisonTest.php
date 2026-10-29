<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract as CaptureContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\HistoricalComparison;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Target;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\Pj0315ProbeFixture;

final class HistoricalComparisonTest extends TestCase
{
    private array $owned = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->owned as $directory) {
                $directory->retire($directory->path(), $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_synthetic_comparison_receipt_is_verified_without_modifying_inputs(): void
    {
        [$directory, $sha, $verifier] = $this->bundle();
        $before = $this->snapshot($directory);
        $this->assertSame(['status' => 'VERIFIED', 'http_calls' => 0, 'historical_as_of_available' => false], $verifier->verify($directory, $sha));
        $this->assertSame($before, $this->snapshot($directory));
    }

    #[DataProvider('invalidBundles')]
    public function test_invalid_inventory_or_seal_is_rejected_without_changing_evidence(string $kind): void
    {
        [$directory, $sha, $verifier] = $this->bundle($kind);
        $before = $this->snapshot($directory);
        $rejected = false;
        try {
            $verifier->verify($directory, $sha);
        } catch (RuntimeException $error) {
            $rejected = true;
            $this->assertNotSame('', $error->getMessage());
        } finally {
            $this->assertSame($before, $this->snapshot($directory));
        }
        $this->assertTrue($rejected, 'Invalid synthetic comparison was VERIFIED: '.$kind);
    }

    public static function invalidBundles(): iterable
    {
        foreach (['FAILED.json', 'extra.json', 'extra-directory', 'missing-COMPLETE.json', 'missing-comparison.json',
            'missing-private-sources.json', 'missing-manifest.json', 'changed-comparison.json',
            'directory-COMPLETE.json', 'directory-comparison.json', 'directory-private-sources.json', 'directory-manifest.json'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function test_rebound_positive_race_id_is_rejected_by_the_fixed_comparison_scope(): void
    {
        [$baseline, $sha, $probe, $probeSha] = $this->sources(12902);
        $target = Target::load($baseline, $sha);
        $this->assertSame(12902, $target->race['race_id']);
        $this->assertSame('2025-01-04', $target->race['race_date']);
        $this->assertSame('28', $target->race['track_code']);
        $this->assertSame(1, $target->race['race_number']);
        $before = $this->snapshot(dirname($baseline));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Historical comparison is restricted to the authorized saved 2025 race.');
        try {
            (new HistoricalComparison)->compare($baseline, $sha, $probe, $probeSha);
        } finally {
            $this->assertSame($before, $this->snapshot(dirname($baseline)));
        }
    }

    public function test_authorized_id_passes_scope_but_does_not_bypass_the_original_probe_contract(): void
    {
        $sources = $this->sources(12901);
        $this->assertSame(12901, Target::load($sources[0], $sources[1])->race['race_id']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected immutable historical probe v1.');
        (new HistoricalComparison)->compare(...$sources);
    }

    private function bundle(?string $kind = null): array
    {
        $directory = $this->root().'/comparison';
        mkdir($directory, 0700);
        $sources = ['SYNTHETIC_BASELINE', str_repeat('a', 64), 'SYNTHETIC_PROBE', str_repeat('b', 64)];
        $result = ['fixture' => 'SYNTHETIC_TEST_ONLY', 'comparison' => ['identity' => 'SAME_SYNTHETIC_RACE']];
        // Isolate terminal verification from the two immutable real-Raw SHA pins; no real Raw is loaded.
        $verifier = $this->createPartialMock(HistoricalComparison::class, ['compare']);
        $verifier->method('compare')->with(...$sources)->willReturn($result);
        $documents = ['private-sources.json' => EvidenceFiles::json(['sources' => $sources]),
            'comparison.json' => EvidenceFiles::json($result)];
        $files = array_map(EvidenceFiles::seal(...), $documents);
        $documents['manifest.json'] = EvidenceFiles::json(['version' => ObservationContract::VERSION,
            'kind' => 'HISTORICAL_COMPARISON', 'code_seal' => ObservationContract::codeSeal(), 'files' => $files]);
        $sha = hash('sha256', $documents['manifest.json']);
        $documents['COMPLETE.json'] = EvidenceFiles::json(['state' => 'COMPLETE', 'manifest_sha256' => $sha]);
        foreach ($documents as $name => $bytes) {
            if ($kind === 'missing-'.$name) {
                continue;
            }
            if ($kind === 'directory-'.$name) {
                mkdir($directory.'/'.$name, 0700);

                continue;
            }
            CaptureContract::writeNew($directory, $name, $kind === 'changed-'.$name ? $bytes.' ' : $bytes);
        }
        if (in_array($kind, ['FAILED.json', 'extra.json'], true)) {
            CaptureContract::writeNew($directory, $kind, EvidenceFiles::json(['fixture' => 'SYNTHETIC_TEST_ONLY']));
        } elseif ($kind === 'extra-directory') {
            mkdir($directory.'/unexpected', 0700);
        }

        return [$directory, $sha, $verifier];
    }

    private function sources(int $raceId): array
    {
        $root = $this->root();
        [$original] = Pj0315ProbeFixture::saved($root);
        $document = json_decode(file_get_contents($original), true, flags: JSON_THROW_ON_ERROR);
        $document['cases'][0]['target']['race']['race_id'] = $raceId;
        $document['cases'][0]['request']['race_id'] = $raceId;
        $document['cases'][0]['log']['race_id'] = $raceId;
        $sha = ObservationContract::write($root, 'rebound-target.json', $document);
        mkdir($root.'/probe', 0700);
        $probeSha = ObservationContract::write($root.'/probe', 'manifest.json', ['version' => 'SYNTHETIC_NOT_REAL_PROBE']);

        return [$root.'/rebound-target.json', $sha, $root.'/probe', $probeSha];
    }

    private function root(): string
    {
        $directory = CompositionResultTemporaryDirectory::create();
        $this->owned[] = $directory;

        return $directory->path();
    }

    private function snapshot(string $root): array
    {
        $result = [];
        foreach (scandir($root) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root.'/'.$name;
            $result[$name] = is_dir($path) ? $this->snapshot($path) : EvidenceFiles::seal(file_get_contents($path));
        }

        return $result;
    }
}
