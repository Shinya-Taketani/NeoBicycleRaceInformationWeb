<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Preflight;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class PrestartApprovalPreflightCommand extends Command
{
    protected $signature = 'keirin:prestart:approval-preflight
        {--input= : Absolute path to bounded offline JSON, never a URL}
        {--scenario=OPERATIONAL_READINESS : OPERATIONAL_READINESS or SIMULATED_SCENARIO}
        {--at= : Explicit offset-qualified clock, simulated scenarios only}';

    protected $description = 'Explain offline approval deficits without fetching, reserving, saving or predicting';

    public function handle(Preflight $preflight): int
    {
        try {
            $scenario = (string) $this->option('scenario');
            Contract::scenario($scenario);
            $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($this->option('at') !== null) {
                if ($scenario !== Contract::SIMULATED || Contract::instant($this->option('at')) === null) {
                    throw new InvalidArgumentException('An explicit evaluation clock is simulated-only and must include an offset.');
                }
                $at = new DateTimeImmutable($this->option('at'));
            }
            $input = [];
            $path = $this->option('input');
            if ($path !== null) {
                if (! is_string($path) || ! str_starts_with($path, '/') || realpath($path) !== $path || is_link($path)
                    || ! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
                    throw new InvalidArgumentException('A readable canonical offline JSON file of at most 1MiB is required.');
                }
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    throw new InvalidArgumentException('Cannot read offline preflight input.');
                }
                try {
                    $bytes = stream_get_contents($stream, 1048577);
                } finally {
                    fclose($stream);
                }
                if ($bytes === false || strlen($bytes) > 1048576 || ! json_decode($bytes, false, 32, JSON_THROW_ON_ERROR) instanceof stdClass) {
                    throw new InvalidArgumentException('A bounded JSON object is required.');
                }
                $input = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            }
            $this->output->write($preflight->evaluate($input, $scenario, $at)->json());

            // A completed explanation of NOT_AUTHORIZED is not a command failure.
            return self::SUCCESS;
        } catch (InvalidArgumentException|JsonException) {
            $this->output->write(json_encode(['contract_version' => Contract::VERSION, 'status' => 'INPUT_ERROR',
                'reason' => 'INVALID_OFFLINE_PREFLIGHT_INPUT', ...Contract::restrictions()], JSON_THROW_ON_ERROR)."\n");

            return self::FAILURE;
        }
    }
}
