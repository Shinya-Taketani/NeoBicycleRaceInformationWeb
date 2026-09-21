<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use RuntimeException;

final class RawReader
{
    public function verify(array $source): array
    {
        Contract::date($source['race_date']);
        $path = $source['absolute_path'];
        if (realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('RAW_MISSING_OR_SYMLINK');
        }
        if ($source['fetch_hash'] !== null && $source['fetch_hash'] !== $source['source_hash']) {
            throw new RuntimeException('SOURCE_HASH_CONFLICT');
        }
        if ($source['fetch_bytes'] !== null && $source['fetch_bytes'] !== $source['raw_response_size']) {
            throw new RuntimeException('SOURCE_SIZE_CONFLICT');
        }
        $seal = ['bytes' => $source['raw_response_size'], 'sha256' => $source['source_hash']];
        if (! is_int($seal['bytes']) || $seal['bytes'] < 1 || $seal['bytes'] > 4194304) {
            throw new RuntimeException('RAW_SIZE_UNSUPPORTED');
        }
        Files::verify($path, $seal);

        return $seal;
    }

    public function read(array $source): string
    {
        $this->verify($source);
        $body = file_get_contents($source['absolute_path']);
        if (! is_string($body) || hash('sha256', $body) !== $source['source_hash']) {
            throw new RuntimeException('RAW_READ_DRIFT');
        }
        [$html] = (new CharacterEncodingConverter)->convertToUtf8($body, $source['content_type'] ?? null);
        if ($source['converted_hash'] !== null && hash('sha256', $html) !== $source['converted_hash']) {
            throw new RuntimeException('CONVERTED_HASH_MISMATCH');
        }

        return $html;
    }
}
