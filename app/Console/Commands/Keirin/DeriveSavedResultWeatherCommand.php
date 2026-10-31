<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\SavedWeather\Derive;
use App\Domain\Keirin\Statistics\SavedWeather\Files;
use Illuminate\Console\Command;
use Throwable;

final class DeriveSavedResultWeatherCommand extends Command
{
    protected $signature = 'keirin:statistics:derive-saved-result-weather {--output=}';

    protected $description = 'Derive the reviewed PR131 all-A input offline, preserving the old data and code provenance.';

    public function handle(Derive $derive): int
    {
        try {
            $input = $derive->run((string) $this->option('output'));
            $this->line(Files::json(['races' => $input['races'], 'manifest' => Files::seal($this->option('output').'/manifest.json'), 'source_connections' => 0]));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Offline weather derivation failed: '.$e::class);

            return self::FAILURE;
        }
    }
}
