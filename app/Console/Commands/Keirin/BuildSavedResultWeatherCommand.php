<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\SavedWeather\Batch;
use App\Domain\Keirin\Statistics\SavedWeather\Files;
use App\Domain\Keirin\Statistics\SavedWeather\Store;
use Illuminate\Console\Command;
use Throwable;

final class BuildSavedResultWeatherCommand extends Command
{
    protected $signature = 'keirin:statistics:build-saved-result-weather {--input=} {--input-sha256=}
        {--output=} {--chunk=100} {--dry-run} {--verify= : Verify an existing output without projection} {--manifest-sha256=}';

    protected $description = 'Project saved PJ0326 weather on a sealed offline scope; never connects to PostgreSQL or HTTP.';

    public function handle(Batch $service, Store $store): int
    {
        try {
            if ($this->option('verify')) {
                $this->line(Files::json($store->verify($this->option('verify'), (string) $this->option('manifest-sha256'))));

                return self::SUCCESS;
            }
            $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
            if ($chunk === false) {
                throw new \RuntimeException('INVALID_CHUNK');
            }
            $result = $service->run((string) $this->option('input'), (string) $this->option('input-sha256'),
                $this->option('output'), (bool) $this->option('dry-run'), $chunk);
            $this->line(Files::json($result));

            return $result['exit_code'];
        } catch (Throwable $e) {
            $this->error('Weather batch failed: '.$e::class);

            return self::FAILURE;
        }
    }
}
