<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\SavedWeather\Files;
use App\Domain\Keirin\Statistics\SavedWeather\Prepare;
use Illuminate\Console\Command;
use Throwable;

final class PrepareSavedResultWeatherCommand extends Command
{
    protected $signature = 'keirin:statistics:prepare-saved-result-weather
        {--parent-manifest=} {--parent-sha256=} {--snapshot=} {--existing-raw-root=} {--raw-root=} {--output=}
        {--supplement-read-only : Explicitly export missing metadata from the fixed PostgreSQL scope}';

    protected $description = 'Seal the saved STAT21 scope and optionally supplement fixed-ID metadata read-only.';

    public function handle(Prepare $service): int
    {
        try {
            foreach (['parent-manifest', 'parent-sha256', 'snapshot', 'existing-raw-root', 'raw-root', 'output'] as $key) {
                if (! is_string($this->option($key)) || $this->option($key) === '') {
                    throw new \RuntimeException('REQUIRED_PREPARATION_OPTION_MISSING');
                }
            }
            $out = $service->run($this->option('parent-manifest'), $this->option('parent-sha256'), $this->option('snapshot'),
                $this->option('existing-raw-root'), $this->option('raw-root'), $this->option('output'), (bool) $this->option('supplement-read-only'));
            $this->line(Files::json(['races' => $out['races'], 'extraction' => $out['extraction'], 'manifest' => Files::seal($this->option('output').'/manifest.json')]));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Weather preparation failed: '.$e::class);

            return self::FAILURE;
        }
    }
}
