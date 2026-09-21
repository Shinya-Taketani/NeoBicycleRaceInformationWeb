<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\AgariBackfillService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class BackfillAgariCommand extends Command
{
    protected $signature = 'keirin:stat35:backfill-agari {--plan} {--execute} {--from=2022-01-01} {--to=2025-12-31} {--chunk=100} {--dry-run}';

    protected $description = 'Plan or backfill agari from verified saved 2022-2025 Raw imports (no network).';

    public function handle(AgariBackfillService $service): int
    {
        try {
            if ((bool) $this->option('plan') === (bool) $this->option('execute') || ($this->option('plan') && $this->option('dry-run'))
                || preg_match('/^[1-9][0-9]*$/D', (string) $this->option('chunk')) !== 1) {
                throw new InvalidArgumentException('Choose --plan or --execute; --dry-run requires --execute; chunk must be positive.');
            }
            $from = (string) $this->option('from');
            $to = (string) $this->option('to');
            $chunk = (int) $this->option('chunk');
            $plan = $service->plan($from, $to, $chunk);
            $result = $this->option('plan') ? $plan : $service->run($from, $to, $chunk, (bool) $this->option('dry-run'),
                fn (array $event) => $this->line(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return ($result['failed'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
