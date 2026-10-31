<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use App\Domain\Keirin\Statistics\Services\SavedResultWeatherProjectionService;
use App\Models\Race;
use App\Models\RaceEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class Batch
{
    public function __construct(private readonly SavedResultWeatherProjectionService $projection) {}

    public function run(string $input, string $sha, ?string $output, bool $dryRun = false, int $chunk = 100): array
    {
        $start = microtime(true);
        if ($chunk < 1 || $chunk > 1000 || (! $dryRun && $output === null)) {
            throw new RuntimeException('INVALID_BATCH_OPTIONS');
        }
        $manifest = Store::input($input, $sha);
        $streams = [];
        if (! $dryRun) {
            Files::directory($output);
            foreach (['race-coverage', 'candidate-inventory', 'projections'] as $name) {
                $streams[$name] = Files::create($output.'/'.$name.'.jsonl');
            }
        }
        $summary = ['contract' => Files::VERSION, 'races' => 0, 'candidates' => 0, 'projected' => 0, 'excluded' => 0,
            'failed' => 0, 'not_processed' => 0, 'w45_projected' => 0, 'additional_projected' => 0,
            'raw_bytes' => 0, 'reasons' => [], 'fields' => [], 'years' => [], 'dry_run' => $dryRun, 'saved' => 0,
            'business_writes' => 0, 'statistic_writes' => 0, 'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED'];
        $failed = false;
        $oldDisk = config('keirin.raw_disk');
        $name = 'stat21_fixed_input';
        $connection = DB::build(['driver' => 'sqlite', 'database' => $manifest['sqlite']['path'], 'prefix' => '', 'foreign_key_constraints' => false]);
        $connection->statement('PRAGMA query_only=ON');
        if ((int) $connection->selectOne('PRAGMA query_only')->query_only !== 1) {
            throw new RuntimeException('SQLITE_NOT_READ_ONLY');
        }
        // Reader's existing models use the active connection, confined to this offline callback.
        $manager = DB::getFacadeRoot();
        $connections = $manager->getConnections();
        if (isset($connections[$name])) {
            throw new RuntimeException('BATCH_CONNECTION_ALREADY_IN_USE');
        }
        DB::extend($name, fn () => $connection);
        $previousConfig = config('database.connections.'.$name);
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $manifest['sqlite']['path']]]);
        $runError = null;
        try {
            DB::usingConnection($name, function () use ($manifest, &$summary, &$failed, $streams): void {
                foreach (Files::rows($manifest['files']['candidates.jsonl']['path']) as $group) {
                    $summary['races']++;
                    $raceCounts = [];
                    foreach ($group['candidates'] as $c) {
                        $reason = $c['reason'];
                        $status = $failed ? 'NOT_PROCESSED_AFTER_FAILURE' : ($reason === null ? 'PROJECTED' : 'EXCLUDED');
                        $projection = null;
                        if ($status === 'PROJECTED') {
                            try {
                                Files::verify($c['raw']);
                                config(['keirin.raw_disk' => 'stat21_raw', 'filesystems.disks.stat21_raw' => ['driver' => 'local', 'root' => $c['raw_root'], 'throw' => true]]);
                                Storage::forgetDisk('stat21_raw');
                                $projection = $this->projection->project(Race::query()->findOrFail($c['race_id']), RaceEntry::query()->findOrFail($c['entry_id']));
                                Store::binding($c, $projection);
                                Files::verify($c['raw']);
                            } catch (Throwable $e) {
                                $failed = true;
                                $status = $e instanceof RuntimeException ? 'FAILED_INTEGRITY' : 'FAILED_TECHNICAL';
                                $reason = $e::class; // No source exception text/credentials in shared diagnostics.
                            }
                        }
                        $summary['candidates']++;
                        $raceCounts[$status] = ($raceCounts[$status] ?? 0) + 1;
                        $year = substr($c['race_date'], 0, 4);
                        $summary['years'][$year][$status] = ($summary['years'][$year][$status] ?? 0) + 1;
                        $counter = match ($status) {
                            'PROJECTED' => 'projected', 'EXCLUDED' => 'excluded', 'NOT_PROCESSED_AFTER_FAILURE' => 'not_processed', default => 'failed'
                        };
                        $summary[$counter]++;
                        if ($reason !== null) {
                            $summary['reasons'][$reason] = ($summary['reasons'][$reason] ?? 0) + 1;
                        }
                        if ($status === 'PROJECTED') {
                            $summary[$c['existing_w45'] ? 'w45_projected' : 'additional_projected']++;
                            $summary['raw_bytes'] += $c['raw']['bytes'];
                            foreach ($projection['raw_fields'] as $field => $v) {
                                $summary['fields'][$field][$v['state']] = ($summary['fields'][$field][$v['state']] ?? 0) + 1;
                            }
                            if ($streams !== []) {
                                Files::append($streams['projections'], ['contract' => Files::VERSION, 'candidate_key' => $c['key'], 'projection' => $projection]);
                                $summary['saved']++;
                            }
                        }
                        if ($streams !== []) {
                            Files::append($streams['candidate-inventory'], ['candidate' => $c, 'status' => $status, 'reason' => $reason]);
                        }
                    }
                    if ($streams !== []) {
                        Files::append($streams['race-coverage'], array_diff_key($group, ['candidates' => true]) + ['statuses' => $raceCounts]);
                    }
                }
            });
            Store::input($input, $sha);
            if ($summary['races'] !== $manifest['races']) {
                throw new RuntimeException('FIXED_RACE_DENOMINATOR_CHANGED');
            }
        } catch (Throwable $e) {
            $failed = true;
            $runError = ['exception' => $e::class, 'reason' => 'SEALED_RUN_INPUT_OR_OUTPUT_FAILURE'];
        } finally {
            DB::purge($name);
            DB::forgetExtension($name);
            config(['database.connections.'.$name => $previousConfig]);
            config(['keirin.raw_disk' => $oldDisk]);
            Storage::forgetDisk('stat21_raw');
            foreach ($streams as $h) {
                fclose($h);
            }
        }
        $summary += ['exit_code' => $failed ? 1 : 0, 'memory_limit' => ini_get('memory_limit'),
            'peak_memory_bytes' => memory_get_peak_usage(true), 'duration_seconds' => microtime(true) - $start, 'run_error' => $runError];
        if (! $dryRun) {
            Files::write($output.'/coverage.json', $summary);
            $csv = Files::create($output.'/coverage.csv');
            fputcsv($csv, ['metric', 'count'], escape: '');
            foreach (['races', 'candidates', 'projected', 'excluded', 'failed', 'not_processed', 'w45_projected', 'additional_projected'] as $key) {
                fputcsv($csv, [$key, $summary[$key]], escape: '');
            }
            fclose($csv);
            $files = [];
            foreach (glob($output.'/*') as $file) {
                $files[basename($file)] = Files::seal($file);
            }
            Files::write($output.'/manifest.json', ['contract' => Files::VERSION, 'input' => Files::seal($input), 'files' => $files]);
            Files::write($output.'/'.($failed ? 'FAILED' : 'COMPLETE').'.json', ['manifest' => Files::seal($output.'/manifest.json'), 'exit_code' => $summary['exit_code']]);
        }

        return $summary;
    }
}
