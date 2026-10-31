<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\SchoolProfileIndexOptionsDto;
use App\Domain\Keirin\Statistics\Services\AcquiredHistoryFeatureBuildService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class BuildAcquiredHistoryFeaturesCommand extends Command
{
    protected $signature = 'keirin:statistics:build-acquired-history
        {--stat=STAT-25 : STAT-09, STAT-16, STAT-25, STAT-27 or STAT-44; existing batches are unchanged}
        {--stat09-profile=legacy-v2 : Explicit STAT09 legacy-v2 or grade-breakdown-v3}
        {--stat01-run-id= : Required saved STAT-01 run}
        {--history-from= : Earliest acquired history date}
        {--from= : First target date}
        {--to= : Last target date}
        {--race-id= : Single target race}
        {--chunk=100 : Keyset page size}
        {--school-profile= : Optional sealed retrospective school projection for STAT-44}
        {--school-profile-sha256= : Required seal when a school projection is provided}
        {--school-profile-index= : Explicit STAT44-only frozen multi-player selection}
        {--school-profile-index-sha256= : Required selection SHA}
        {--dry-run : No statistic writes}';

    protected $description = 'Build retrospective disqualification facts; no sanctions, points or prediction authorization.';

    public function handle(AcquiredHistoryFeatureBuildService $service): int
    {
        try {
            $race = $this->option('race-id');
            $from = $this->option('from');
            $to = $this->option('to');
            $profilePath = $this->option('school-profile');
            $indexPath = $this->option('school-profile-index');
            if (($profilePath === null) !== ($this->option('school-profile-sha256') === null)
                || ($indexPath === null) !== ($this->option('school-profile-index-sha256') === null)
                || ($profilePath !== null && $indexPath !== null)) {
                throw new InvalidArgumentException('School profile modes and SHA options must be paired and exclusive.');
            }
            $index = $indexPath === null ? null : new SchoolProfileIndexOptionsDto((string) $indexPath, (string) $this->option('school-profile-index-sha256'));
            $profile = null;
            if ($profilePath !== null) {
                if (! is_string($profilePath) || ! is_file($profilePath) || hash_file('sha256', $profilePath) !== $this->option('school-profile-sha256')) {
                    throw new InvalidArgumentException('School projection seal mismatch.');
                }
                $profile = json_decode(file_get_contents($profilePath), true, flags: JSON_THROW_ON_ERROR);
            }
            if (($race !== null && ($from !== null || $to !== null)) || ($race === null && ($from === null || $to === null))) {
                throw new InvalidArgumentException('Specify --race-id OR --from and --to.');
            }
            $out = $service->build(new Batch02BuildOptionsDto($this->positive($this->option('stat01-run-id')),
                $this->date($this->option('history-from')), $from === null ? null : $this->date($from),
                $to === null ? null : $this->date($to), $race === null ? null : $this->positive($race),
                $this->positive($this->option('chunk')), (bool) $this->option('dry-run')), (string) $this->option('stat'), $profile,
                (string) $this->option('stat09-profile'), $index);
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function positive(mixed $value): int
    {
        if (! is_scalar($value) || preg_match('/^[1-9]\d*$/D', (string) $value) !== 1 || (float) $value > PHP_INT_MAX) {
            throw new InvalidArgumentException('Positive integer required.');
        }

        return (int) $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^202[2-5]-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('An explicit 2022-2025 date is required.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }

        return $date;
    }
}
