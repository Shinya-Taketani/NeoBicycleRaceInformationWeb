<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\OfficialMethodHistory;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Statistics\OfficialResultEvidence\Verifier;
use App\Models\Race;
use App\Models\RaceEntry;

class Reader
{
    private readonly Verifier $verifier;

    public function __construct(RawReader $raw, RaceLiveResultParser $parser, EmbeddedJsonExtractor $json)
    {
        $this->verifier = new Verifier($raw, $parser, $json);
    }

    public function read(Race $race, RaceEntry $entry): array
    {
        $binding = $this->verifier->read($race, $entry);
        if (! $binding['evidence_verified'] || ($binding['participation'] ?? null) === 'CANCELLED') {
            return $binding;
        }
        $outcome = Contract::outcome($binding['rank'], $binding['result_status'], $binding['normalized_method']);
        unset($binding['normalized_method']);
        $binding['issues'] = $outcome['method_missing'] ? ['MISSING_TOP2_METHOD'] : [];

        return $outcome + $binding;
    }
}
