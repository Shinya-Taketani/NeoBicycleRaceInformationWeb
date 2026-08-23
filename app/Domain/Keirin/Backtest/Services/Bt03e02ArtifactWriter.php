<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

final class Bt03e02ArtifactWriter
{
    public function __construct(
        private readonly Bt03eArtifactFilesystem $filesystem,
        private readonly CanonicalHasher $hasher,
    ) {}

    /** @param array<string,mixed> $summary @return array{bundle_directory:string,result_json:string,manifest_json:string,reproducibility_hash:string,result_sha256:string,manifest_sha256:string} */
    public function write(string $directory, array $summary): array
    {
        $root = rtrim($directory, '/') ?: '/';
        $this->filesystem->ensureDirectory($root);
        $name = 'bt03e02-development-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
        $temporary = $root.'/.'.$name.'.tmp';
        $final = $root.'/'.$name;
        if ($this->filesystem->exists($temporary) || $this->filesystem->exists($final)) {
            throw new RuntimeException('BT-03E-02 artifact bundle already existed.');
        }
        $this->filesystem->createDirectory($temporary);
        try {
            $reproducibilityHash = $summary['reproducibility_hash'] ?? null;
            if (! is_string($reproducibilityHash) || preg_match('/\A[a-f0-9]{64}\z/', $reproducibilityHash) !== 1) {
                throw new RuntimeException('BT-03E-02 reproducibility hash was invalid.');
            }
            $result = $summary;
            $resultJson = $this->json($result);
            $resultSha256 = hash('sha256', $resultJson);
            $resultPath = $temporary.'/result.json';
            $this->filesystem->writeExact($resultPath, $resultJson);
            $manifest = [
                'artifact_version' => Bt03e02Contract::ARTIFACT_VERSION,
                'files' => [[
                    'name' => 'result.json',
                    'bytes' => strlen($resultJson),
                    'sha256' => $resultSha256,
                ]],
            ];
            $manifest['manifest_sha256'] = $this->hasher->hash($manifest);
            $manifestSha256 = $manifest['manifest_sha256'];
            $manifestPath = $temporary.'/manifest.json';
            $this->filesystem->writeExact($manifestPath, $this->json($manifest));
            if ($this->filesystem->size($resultPath) !== strlen($resultJson)) {
                throw new RuntimeException('BT-03E-02 result artifact size verification failed.');
            }
            $this->filesystem->publish($temporary, $final);
        } catch (Throwable $throwable) {
            try {
                $this->filesystem->removeDirectory($temporary);
            } catch (Throwable) {
                // Preserve the publication failure.
            }
            throw $throwable;
        }

        return [
            'bundle_directory' => $final,
            'result_json' => $final.'/result.json',
            'manifest_json' => $final.'/manifest.json',
            'reproducibility_hash' => $reproducibilityHash,
            'result_sha256' => $resultSha256,
            'manifest_sha256' => $manifestSha256,
        ];
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }
}
