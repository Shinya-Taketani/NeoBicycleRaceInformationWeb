<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\Parsers\PayoutParser;
use App\Domain\Keirin\Scraping\Parsers\RaceResultParser;
use Illuminate\Support\Facades\File;

class RaceResultImportService
{
    public function __construct(
        private readonly RaceResultParser $results,
        private readonly PayoutParser $payouts,
    ) {}

    /**
     * @return array{results:int,payouts:int}
     */
    public function parseRawFile(string $rawFile): array
    {
        $html = File::get($rawFile);

        return [
            'results' => count($this->results->parse($html)),
            'payouts' => count($this->payouts->parse($html)),
        ];
    }
}
