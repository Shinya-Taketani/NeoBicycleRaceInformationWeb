<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Contracts\LogisticTrainingRowSource;
use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use RuntimeException;

class SpoolLogisticTrainingRowSource implements LogisticTrainingRowSource
{
    public function __construct(private readonly ImmutableBt02Spool $spool) {}

    public function rows(): iterable
    {
        $metadata = $this->spool->metadata();
        $handle = fopen($this->spool->path(), 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the sealed BT-02 spool for replay.');
        }
        $hash = hash_init('sha256');
        $count = 0;
        $bytes = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-02 spool row did not end with LF.');
                }
                hash_update($hash, $line);
                $bytes += strlen($line);
                $count++;
                yield $this->decode($line);
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully read the sealed BT-02 spool.');
            }
            $actualHash = hash_final($hash);
            if ($count !== $metadata->rowCount || $bytes !== $metadata->byteCount || ! hash_equals($metadata->sha256, $actualHash)) {
                throw new RuntimeException('BT-02 spool replay identity did not match its seal metadata.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function decode(string $line): LogisticTrainingRowDto
    {
        $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)
            || array_keys($data) !== ['format_version', 'features', 'label']
            || $data['format_version'] !== ImmutableBt02Spool::FORMAT_VERSION
            || ! is_array($data['features'])
            || $data['features'] === []
            || ! in_array($data['label'], [0, 1], true)) {
            throw new RuntimeException('BT-02 spool JSON row was invalid.');
        }
        $features = [];
        foreach ($data['features'] as $feature) {
            if (! is_string($feature) || ! is_numeric($feature)) {
                throw new RuntimeException('BT-02 spool JSON feature was invalid.');
            }
            $number = (float) $feature;
            if (! is_finite($number)) {
                throw new RuntimeException('BT-02 spool JSON feature was not finite.');
            }
            $features[] = $number;
        }

        return new LogisticTrainingRowDto($features, $data['label']);
    }
}
