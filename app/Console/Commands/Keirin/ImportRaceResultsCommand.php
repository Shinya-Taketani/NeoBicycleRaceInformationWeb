<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RaceResultImportService;
use Illuminate\Console\Command;

class ImportRaceResultsCommand extends Command
{
    protected $signature = 'keirin:races:import-results
        {--race-id= : Internal races.id}
        {--external-race-id= : races.external_race_id}
        {--raw-file= : Saved result HTML}
        {--source-url= : Source URL for the saved result HTML}
        {--result-status= : UNAVAILABLE, PROVISIONAL, UNDER_REVIEW, CONFIRMED, CORRECTED, CANCELLED}';

    protected $description = 'Import race results and payouts from a saved KEIRIN.JP result HTML.';

    public function handle(RaceResultImportService $results): int
    {
        $raceId = $this->option('race-id');
        $externalRaceId = $this->option('external-race-id');
        if (($raceId === null && $externalRaceId === null) || ($raceId !== null && $externalRaceId !== null)) {
            $this->error('Specify exactly one of --race-id or --external-race-id.');

            return self::FAILURE;
        }

        if ($raceId !== null && (! is_numeric($raceId) || (int) $raceId < 1 || (string) (int) $raceId !== (string) $raceId)) {
            $this->error('--race-id must be a positive integer.');

            return self::FAILURE;
        }

        $rawFile = $this->option('raw-file');
        if (! is_string($rawFile) || $rawFile === '') {
            $this->error('--raw-file is required.');

            return self::FAILURE;
        }

        if (! is_file($rawFile) || ! is_readable($rawFile)) {
            $this->error('--raw-file must exist and be readable.');

            return self::FAILURE;
        }

        $sourceUrl = $this->option('source-url');
        if (! is_string($sourceUrl) || $sourceUrl === '') {
            $this->error('--source-url is required.');

            return self::FAILURE;
        }

        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            $this->error('--source-url must be a valid URL.');

            return self::FAILURE;
        }

        $status = (string) $this->option('result-status');
        if ($status === '') {
            $this->error('--result-status is required.');

            return self::FAILURE;
        }

        if (! in_array($status, ['UNAVAILABLE', 'PROVISIONAL', 'UNDER_REVIEW', 'CONFIRMED', 'CORRECTED', 'CANCELLED'], true)) {
            $this->error('--result-status is invalid.');

            return self::FAILURE;
        }

        try {
            $result = $results->importRawFile(
                raceId: $raceId !== null ? (int) $raceId : null,
                externalRaceId: is_string($externalRaceId) ? $externalRaceId : null,
                rawFile: $rawFile,
                sourceUrl: $sourceUrl,
                requestedResultStatus: $status,
            );
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info("race_id={$result['race']->id}");
        $this->line("results={$result['results']} payouts={$result['payouts']}");
        $this->line("import_id={$result['import']->id} import_status={$result['status']}");

        return self::SUCCESS;
    }
}
