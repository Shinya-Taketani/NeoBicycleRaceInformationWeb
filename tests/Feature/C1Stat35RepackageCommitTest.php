<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\LegacySource;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\C1Stat35CompositionFinalFixture as Models;
use Tests\Support\C1Stat35RepackageFixture as Fixture;
use Tests\TestCase;
use Throwable;

class C1Stat35RepackageCommitTest extends TestCase
{
    private static ?array $models = null;

    private static string $shared;

    private string $root;

    private LegacySource $pin;

    protected function setUp(): void
    {
        parent::setUp();
        FinalC1Stat35CompositionCommand::denyExternalAccess();
        $this->root = Files::directory(sys_get_temp_dir().'/repackage-commit-'.bin2hex(random_bytes(8)));
        if (self::$models === null) {
            self::$shared = sys_get_temp_dir().'/repackage-models-'.bin2hex(random_bytes(8));
            ob_start();
            try {
                self::$models = Models::make(self::$shared);
            } finally {
                ob_end_clean();
            }
        }
        $this->pin = Fixture::make($this->root, self::$models);
        Fixture::bind($this->pin);
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$models !== null) {
            self::remove(self::$shared);
            self::$models = null;
        }
        parent::tearDownAfterClass();
    }

    #[DataProvider('interruptions')]
    public function test_real_repackage_sigkill_and_byte_exact_relocation_require_postplacement_receipt(string $point): void
    {
        $destination = $this->root.'/export';
        $child = $this->child($destination, $point);
        $child->start();
        try {
            $this->waitFor($destination.'.ready', $child);
            $child->signal(9);
            $child->wait();
            $this->assertSame(137, $child->getExitCode());
            $stage = glob($destination.'.inprogress-*');
            $this->assertCount($point === 'before' ? 1 : 0, $stage);
            $source = $point === 'before' ? $stage[0] : $destination;
            $this->assertFileDoesNotExist($source.'/FAILED.json');
            $this->assertSame('REPACKAGE', Files::json($source.'/publication.json')['kind']);
            $this->assertSame('PREPARED', Files::json($source.'/publication.json')['state']);
            $this->assertSame(Contract::REPACKAGE_PUBLICATION_VERSION, Files::json($source.'/artifact.json')['publication_version']);
            if ($point === 'before') {
                $this->assertDirectoryDoesNotExist($destination);
                $this->assertFileDoesNotExist($destination.'.placement-called');
            }
            if ($point !== 'committed') {
                $this->assertFileDoesNotExist($source.'/RELEASE_COMMITTED.json');
            }
            Files::directory($this->root.'/portable');
            $moved = $this->root.'/portable/package';
            $this->copyExact($source, $moved);
            $this->assertPublic($moved, $point === 'committed', 'copied');
            if ($point !== 'before') {
                $this->assertPublic($source, $point === 'committed', 'original');
            }
            if ($point === 'writing') {
                $this->assertNotSame([], glob($source.'/.release-*.json.partial'));
            }
            if ($point === 'committed') {
                $this->independentPredict($moved);
            }
        } finally {
            if ($child->isRunning()) {
                $child->stop(0);
            }
        }
    }

    public static function interruptions(): array
    {
        return [['before'], ['placed'], ['writing'], ['committed']];
    }

    #[DataProvider('receiptFailures')]
    public function test_receipt_failure_retains_only_own_unpublished_evidence(string $fault): void
    {
        $destination = $this->root.'/export';
        $child = $this->child($destination, $fault);
        $child->run();
        $this->assertSame(1, $child->getExitCode(), $child->getOutput());
        $this->assertDirectoryExists($destination);
        $this->assertFileExists($destination.'/FAILED.json');
        $this->assertPublic($destination, false, 'failed');
        unlink($destination.'/FAILED.json');
        $this->assertPublic($destination, false, 'no-failed-marker');
        if ($fault === 'receipt-conflict') {
            $this->assertSame('{}', file_get_contents($destination.'/RELEASE_COMMITTED.json'));
        } else {
            $this->assertFileDoesNotExist($destination.'/RELEASE_COMMITTED.json');
        }
        $retry = Fixture::repackage($this->pin, new Publication)->run($this->pin->source, $this->root.'/retry');
        $this->assertSame(0, $retry['retraining_count']);
        $this->assertPublic(dirname($retry['artifact']), true, 'retry');
    }

    public static function receiptFailures(): array
    {
        return [['write-fail'], ['partial'], ['receipt-conflict']];
    }

    public function test_postreceipt_auxiliary_exception_remains_committed_without_failed_marker(): void
    {
        $publication = new class extends Publication
        {
            public function releaseRepackage(string $destination, array $attempt): void
            {
                parent::releaseRepackage($destination, $attempt);
                throw new RuntimeException('Auxiliary response failed after release.');
            }
        };
        $result = Fixture::repackage($this->pin, $publication)->run($this->pin->source, $this->root.'/export');
        $this->assertStringContainsString('after release', $result['postcommit_warning']);
        $this->assertFileDoesNotExist($this->root.'/export/FAILED.json');
        $this->assertPublic($this->root.'/export', true, 'auxiliary');
    }

    public function test_exception_after_placement_is_not_misreported_as_committed(): void
    {
        $publication = new class extends Publication
        {
            public function commit(string $stage, string $destination): void
            {
                parent::commit($stage, $destination);
                throw new RuntimeException('Placement succeeded but release not reached.');
            }
        };
        try {
            Fixture::repackage($this->pin, $publication)->run($this->pin->source, $this->root.'/export');
            $this->fail('Content COMPLETE alone was treated as public.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('release not reached', $e->getMessage());
        }
        $this->assertFileExists($this->root.'/export/FAILED.json');
        $this->assertFileDoesNotExist($this->root.'/export/RELEASE_COMMITTED.json');
        $this->assertPublic($this->root.'/export', false, 'postplacement-exception');
    }

    #[DataProvider('badInputFields')]
    public function test_actual_repackage_public_cli_preserves_outcome_year_and_input_version_rejections(string $fault): void
    {
        $result = Fixture::repackage($this->pin, new Publication)->run($this->pin->source, $this->root.'/export');
        $race = Models::feature(100);
        if ($fault === '2026') {
            $race['year'] = 2026;
        } elseif ($fault !== 'input-version') {
            $race['entries'][0][$fault] = 1;
        }
        $input = $this->root.'/bad-input.jsonl';
        Input::write($input, [$race]);
        if ($fault === 'input-version') {
            $meta = Files::json($input.'.input.json');
            $meta['version'] = 'unknown';
            unlink($input.'.input.json');
            Jsonl::json($input.'.input.json', $meta);
        }
        $this->artisan('keirin:c1:stat35-composition-predict', ['--artifact' => $result['artifact'],
            '--input' => $input, '--output-dir' => $this->root.'/bad-predictions'])->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root.'/bad-predictions');
    }

    public static function badInputFields(): array
    {
        return [['2026'], ['input-version'], ['rank'], ['status'], ['result'], ['payout']];
    }

    #[DataProvider('receiptDrifts')]
    public function test_receipt_cannot_be_replaced_by_another_attempt_or_mismatched_evidence(string $fault): void
    {
        $service = Fixture::repackage($this->pin, new Publication);
        $service->run($this->pin->source, $this->root.'/a');
        $service->run($this->pin->source, $this->root.'/b');
        $receipt = $this->root.'/a/RELEASE_COMMITTED.json';
        $data = Files::json($receipt);
        if ($fault === 'other-attempt') {
            $data = Files::json($this->root.'/b/RELEASE_COMMITTED.json');
        } elseif ($fault === 'missing') {
            unlink($receipt);
        } elseif ($fault === 'partial-final') {
            file_put_contents($receipt, '{');
        } else {
            match ($fault) {
                'id' => $data['publication_id'] = str_repeat('a', 48),
                'artifact' => $data['artifact'] = ['bytes' => 1, 'sha256' => str_repeat('0', 64)],
                'prepared' => $data['prepared_publication'] = ['bytes' => 1, 'sha256' => str_repeat('0', 64)],
                'runtime' => $data['runtime_code_sha256'] = str_repeat('0', 64),
                'version' => $data['version'] = 'unknown',
                'state' => $data['state'] = 'PREPARED',
                'type' => $data['publication_id'] = [],
                'placement' => $data['committed_after_placement'] = false,
            };
        }
        if (! in_array($fault, ['missing', 'partial-final'], true)) {
            unlink($receipt);
            Jsonl::json($receipt, $data);
        }
        $this->assertPublic($this->root.'/a', false, 'tampered');
        $this->assertPublic($this->root.'/b', true, 'other-untouched');
    }

    public static function receiptDrifts(): array
    {
        return array_map(static fn ($s) => [$s], ['other-attempt', 'missing', 'partial-final', 'id', 'artifact', 'prepared', 'runtime', 'version', 'state', 'type', 'placement']);
    }

    public function test_other_directory_with_identical_bytes_is_not_owned_and_cannot_receive_receipt_or_failed(): void
    {
        $publication = new class extends Publication
        {
            public function commit(string $stage, string $destination): void
            {
                parent::commit($stage, $destination);
                rename($destination, $destination.'-ours');
                mkdir($destination);
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destination.'-ours', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                    $path = $destination.substr($file->getPathname(), strlen($destination.'-ours'));
                    $file->isDir() ? mkdir($path) : copy($file->getPathname(), $path);
                }
            }
        };
        try {
            Fixture::repackage($this->pin, $publication)->run($this->pin->source, $this->root.'/export');
            $this->fail('Receipt was attached to another inode.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }
        foreach (['export', 'export-ours'] as $name) {
            $this->assertFileDoesNotExist($this->root.'/'.$name.'/RELEASE_COMMITTED.json');
            $this->assertFileDoesNotExist($this->root.'/'.$name.'/FAILED.json');
            $this->assertPublic($this->root.'/'.$name, false, $name);
        }
    }

    public function test_same_destination_two_repackages_publish_once_without_changing_winner(): void
    {
        $destination = $this->root.'/export';
        $a = $this->child($destination, 'before');
        $a->start();
        try {
            $this->waitFor($destination.'.ready', $a);
            $b = $this->child($destination, 'normal');
            $b->run();
            $this->assertSame(1, $b->getExitCode());
            $this->assertStringContainsString('locked', $b->getErrorOutput());
            file_put_contents($destination.'.release', 'release');
            $a->wait();
            $this->assertSame(0, $a->getExitCode(), $a->getErrorOutput());
            $winner = $this->tree($destination);
            $c = $this->child($destination, 'normal');
            $c->run();
            $this->assertSame(1, $c->getExitCode());
            $this->assertSame($winner, $this->tree($destination));
            $this->assertPublic($destination, true, 'winner');
        } finally {
            if ($a->isRunning()) {
                $a->stop(0);
            }
        }
    }

    #[DataProvider('existingDestinations')]
    public function test_repackage_preserves_existing_destinations(string $kind): void
    {
        $destination = $this->root.'/export';
        if ($kind === 'file') {
            file_put_contents($destination, 'existing');
        } elseif ($kind === 'directory') {
            mkdir($destination);
            file_put_contents($destination.'/keep', 'existing');
        } else {
            symlink($this->root.'/legacy', $destination);
        }
        $before = lstat($destination);
        $child = $this->child($destination, 'normal');
        $child->run();
        $this->assertSame(1, $child->getExitCode());
        $this->assertSame($before, lstat($destination));
        $this->assertSame([], glob($destination.'.inprogress-*'));
    }

    public static function existingDestinations(): array
    {
        return [['file'], ['directory'], ['symlink']];
    }

    public function test_repackage_copies_parent_bytes_without_resolving_training_and_old_v2_cannot_load(): void
    {
        foreach ([Trainer::class, Optimizer::class, Bt03e03OneSeSelector::class] as $class) {
            $this->app->bind($class, static fn () => throw new RuntimeException('No training path allowed.'));
        }
        $before = $this->tree($this->pin->source);
        $result = Fixture::repackage($this->pin, new Publication)->run($this->pin->source, $this->root.'/export');
        $this->assertSame(0, $result['retraining_count']);
        $this->assertSame($before, $this->tree($this->pin->source));
        foreach ($result['models'] as $path => $seal) {
            Files::verify($this->root.'/export/'.$path, $seal);
            $this->assertSame(file_get_contents($this->pin->source.'/run-01/package/'.$path), file_get_contents($this->root.'/export/'.$path));
        }
        $this->assertPublic($this->root.'/export', true, 'byte-exact');
        $proof = Files::json($this->root.'/export/publication.json');
        $proof['version'] = Contract::PUBLICATION_VERSION;
        $proof['state'] = 'COMMITTED';
        unlink($this->root.'/export/publication.json');
        unlink($this->root.'/export/COMPLETE.json');
        Jsonl::json($this->root.'/export/publication.json', $proof);
        Jsonl::json($this->root.'/export/COMPLETE.json', Files::identity($this->root.'/export/publication.json'));
        $this->assertPublic($this->root.'/export', false, 'old-v2');
    }

    private function assertPublic(string $directory, bool $accepted, string $name): void
    {
        try {
            $model = app(Package::class)->load($directory.'/artifact.json');
            $this->assertTrue($accepted, 'Uncommitted package loaded.');
            $this->assertSame(17, $model['c2']->layout->featureCount());
        } catch (Throwable $e) {
            if ($e instanceof AssertionFailedError) {
                throw $e;
            }
            $this->assertFalse($accepted, $e->getMessage());
        }
        $this->artisan('keirin:c1:stat35-composition-predict', ['--artifact' => $directory.'/artifact.json',
            '--input' => $this->pin->source.'/verified-inputs/features-2025.jsonl', '--output-dir' => $this->root.'/prediction-'.$name])
            ->assertExitCode($accepted ? 0 : 1);
        if (! $accepted) {
            $this->assertDirectoryDoesNotExist($this->root.'/prediction-'.$name);
        }
    }

    private function independentPredict(string $package): void
    {
        $portable = dirname($package);
        foreach (['', '.manifest.json', '.input.json'] as $suffix) {
            copy($this->pin->source.'/verified-inputs/features-2025.jsonl'.$suffix, $portable.'/input.jsonl'.$suffix);
        }
        $script = <<<'PHP'
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap();
$pin = unserialize(base64_decode($argv[1]));
Tests\Support\C1Stat35RepackageFixture::bind($pin);
try {
    if (is_readable($pin->source.'/manifest.json')) { throw new RuntimeException('Old source readable.'); }
} catch (ErrorException $e) {
    if (!str_contains($e->getMessage(), 'open_basedir')) { throw $e; }
}
exit($kernel->call('keirin:c1:stat35-composition-predict', ['--artifact'=>$argv[2].'/package/artifact.json',
    '--input'=>$argv[2].'/input.jsonl','--output-dir'=>$argv[2].'/independent']));
PHP;
        $p = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'open_basedir='.base_path().PATH_SEPARATOR.$portable,
            '-r', $script, base64_encode(serialize($this->pin)), $portable], base_path(), ['APP_ENV' => 'testing']);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $this->assertSame(Files::identity($this->root.'/public-predictions-2025.jsonl'), Files::identity($portable.'/independent/predictions.jsonl'));
    }

    private function child(string $destination, string $point): Process
    {
        $script = <<<'PHP'
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand::denyExternalAccess();
$pin = unserialize(base64_decode($argv[1]));
$publication = new class($argv[3]) extends App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication {
    public function __construct(private string $point) {}
    public function commit(string $stage, string $destination): void {
        if ($this->point === 'before') { $this->pause($destination); }
        file_put_contents($destination.'.placement-called', 'placement');
        parent::commit($stage, $destination);
        if ($this->point === 'placed') { $this->pause($destination); }
    }
    protected function writeReceipt(string $temporary, array $receipt): void {
        if ($this->point === 'write-fail') { throw new RuntimeException('Injected receipt write failure'); }
        if (in_array($this->point, ['writing','partial'], true)) {
            file_put_contents($temporary.'.partial', '{');
            if ($this->point === 'writing') { $this->pause(dirname($temporary)); }
            throw new RuntimeException('Injected partial receipt failure');
        }
        parent::writeReceipt($temporary,$receipt);
    }
    protected function publishReceipt(string $temporary, string $destination): void {
        if ($this->point === 'receipt-conflict') { file_put_contents($destination, '{}'); }
        parent::publishReceipt($temporary,$destination);
    }
    public function releaseRepackage(string $destination, array $attempt): void {
        parent::releaseRepackage($destination,$attempt);
        if ($this->point === 'committed') { $this->pause($destination); }
    }
    private function pause(string $destination): void {
        file_put_contents($destination.'.ready', 'ready');
        while (!file_exists($destination.'.release')) { usleep(10000); }
    }
};
try { echo json_encode(Tests\Support\C1Stat35RepackageFixture::repackage($pin,$publication)->run($pin->source,$argv[2]), JSON_THROW_ON_ERROR); }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }
PHP;

        return new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, base64_encode(serialize($this->pin)), $destination, $point],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array']);
    }

    private function waitFor(string $file, Process $process): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($file, $process->getErrorOutput());
    }

    private function copyExact(string $from, string $to): void
    {
        mkdir($to);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $path = $to.substr($file->getPathname(), strlen($from));
            $file->isDir() ? mkdir($path) : copy($file->getPathname(), $path);
        }
        $this->assertSame($this->tree($from), $this->tree($to));
    }

    private function tree(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = Files::identity($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    private static function remove(string $root): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}
