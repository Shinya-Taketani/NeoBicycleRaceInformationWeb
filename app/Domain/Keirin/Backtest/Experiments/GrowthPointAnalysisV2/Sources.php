<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Store as V1Store;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class Sources
{
    public function __construct(private readonly V1Store $v1) {}

    public function open(string $path): array
    {
        $manifest = $this->v1->verify($path);
        $files = [];
        foreach ($manifest['files'] as $name => $seal) {
            $files[$path.'/'.$name] = $seal;
        }
        foreach (['manifest.json', 'LOCKED.json'] as $name) {
            $files[$path.'/'.$name] = Files::identity($path.'/'.$name);
        }
        $source = ['path' => $path, 'files' => $files, 'code' => Files::json($path.'/code.json')];
        $this->verify($source);

        return $source;
    }

    public function verify(array $source): void
    {
        foreach ($source['files'] as $path => $seal) {
            Files::verify($path, $seal);
        }
        $this->v1->verify($source['path']);
        foreach ($source['code']['files'] as $path => $seal) {
            if (str_contains($path, '..') || str_starts_with($path, '/')) {
                throw new RuntimeException('Invalid v1 code path.');
            }
            Files::verify(base_path($path), $seal);
        }
    }
}
