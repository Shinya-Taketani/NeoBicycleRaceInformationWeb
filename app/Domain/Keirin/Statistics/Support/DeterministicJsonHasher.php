<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

class DeterministicJsonHasher
{
    /** @param array<string, mixed> $input */
    public function hash(array $input): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($input),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
