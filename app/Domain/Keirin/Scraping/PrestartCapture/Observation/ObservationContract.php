<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract as CaptureContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;

final class ObservationContract
{
    public const VERSION = 'PJ0315-OBSERVATION-RUNNER-v1';

    public const URI = 'https://keirin.jp/pc/racelive';

    public const LEDGER_ROOT = '/home/shinya/neo-keirin-artifacts/prestart-capture-pj0315-observation-runner-01/authorization-ledgers';

    public static function restrictions(): array
    {
        return [...Contract::restrictions(), 'operational_decisions' => 'PROPOSED_NOT_APPROVED',
            'permission_status' => 'UNVERIFIED', 'method_selected' => 'NONE', 'selected_lambda' => 'NONE',
            'second_source_status' => 'SECOND_SOURCE_NOT_CAPTURED', 'two_source_consistency' => 'NOT_ESTABLISHED'];
    }

    public static function codeSeal(): array
    {
        $files = glob(__DIR__.'/*.php') ?: throw new RuntimeException('Code inventory unavailable.');
        $files[] = dirname(__DIR__, 5).'/Console/Commands/Keirin/ObservePj0315Command.php';
        $seal = [];
        foreach ($files as $file) {
            $seal[basename($file)] = hash_file('sha256', $file) ?: throw new RuntimeException('Code seal unavailable.');
        }
        ksort($seal);
        $dependencies = [];
        $paths = [...(glob(dirname(__DIR__).'/Operational/*.php') ?: []),
            dirname(__DIR__).'/Pj0315Probe/Target.php', dirname(__DIR__).'/Pj0315Probe/Inspector.php',
            dirname(__DIR__, 2).'/PrestartEvidence/Attributes/ScenarioTiming.php',
            dirname(__DIR__, 3).'/Statistics/Support/DeterministicJsonHasher.php', dirname(__DIR__, 6).'/composer.lock'];
        foreach ($paths as $path) {
            $dependencies[substr($path, strlen(dirname(__DIR__, 6)) + 1)] = hash_file('sha256', $path)
                ?: throw new RuntimeException('Dependency seal unavailable.');
        }
        ksort($dependencies);

        return ['observation' => $seal, 'direct_dependencies' => $dependencies, 'frozen_bridge' => CaptureContract::codeSeal()];
    }

    public static function readDocument(string $file, string $sha): array
    {
        if (! Contract::sha($sha)) {
            throw new RuntimeException('External document SHA required.');
        }
        $bytes = EvidenceFiles::read(dirname($file), basename($file));
        if (! hash_equals($sha, hash('sha256', $bytes))) {
            throw new RuntimeException('Document SHA mismatch.');
        }

        return EvidenceFiles::object($bytes);
    }

    public static function write(string $directory, string $name, array $document): string
    {
        $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
        CaptureContract::writeNew($directory, $name, $bytes);

        return hash('sha256', $bytes);
    }
}
