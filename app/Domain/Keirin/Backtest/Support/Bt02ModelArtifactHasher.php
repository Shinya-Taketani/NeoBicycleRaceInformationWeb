<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

class Bt02ModelArtifactHasher
{
    /** @param array<string, mixed> $artifact */
    public function hash(array $artifact): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($artifact),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_float($value)) {
            return sprintf('%.17g', $value);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map($this->canonicalize(...), $value);
    }
}
