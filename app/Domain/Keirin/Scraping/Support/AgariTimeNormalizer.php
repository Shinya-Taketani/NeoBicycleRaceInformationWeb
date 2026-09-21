<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

use App\Domain\Keirin\Scraping\DTO\RaceResultDto;
use App\Domain\Keirin\Scraping\Enums\AgariStatus;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;

final class AgariTimeNormalizer
{
    public const VERSION = 'AGARI-TIME-v1';

    public function result(RaceResultDto $result): array
    {
        return $this->normalize($result->agariRawText ?? $result->finishTime, $result->status);
    }

    /** @return array{agari_raw_text:?string,agari_time_seconds:?string,agari_status:string} */
    public function normalize(?string $raw, RaceEntryResultStatus $resultStatus): array
    {
        $text = HtmlTextNormalizer::normalize($raw);
        $seconds = null;
        $status = AgariStatus::Missing;
        if ($text !== null) {
            $status = AgariStatus::InvalidFormat;
            if (preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $text) === 1) {
                [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '');
                $integer = ltrim($integer, '0') ?: '0';
                $fraction = rtrim($fraction, '0');
                if ($integer !== '0' || $fraction !== '') {
                    $seconds = $integer.($fraction === '' ? '' : '.'.$fraction);
                    $status = in_array($resultStatus, [RaceEntryResultStatus::Finished, RaceEntryResultStatus::Tied], true)
                        ? AgariStatus::Valid : AgariStatus::ObservedAbnormalResult;
                }
            }
        }

        return ['agari_raw_text' => $raw, 'agari_time_seconds' => $seconds, 'agari_status' => $status->value];
    }
}
