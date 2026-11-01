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
    public function __construct(private readonly SavedResultWeatherProjectionService $projection, private readonly Writer $writer) {}

    public function run(string $input, string $sha, ?string $output, bool $dryRun = false, int $chunk = 100): array
    {
        $start = microtime(true);
        if ($chunk < 1 || $chunk > 1000 || (! $dryRun && $output === null)) {
            throw new RuntimeException('INVALID_BATCH_OPTIONS');
        }
        $manifest = Store::input($input, $sha);
        $summary = ['contract' => Files::VERSION, 'races' => 0, 'candidates' => 0, 'projected' => 0, 'excluded' => 0,
            'failed' => 0, 'not_processed' => 0, 'calculated' => 0, 'saved' => 0, 'projection_rows_written' => 0,
            'race_statuses' => [], 'w45_projected' => 0, 'additional_projected' => 0,
            'raw_bytes' => 0, 'reasons' => [], 'fields' => [], 'years' => [], 'dry_run' => $dryRun,
            'business_writes' => 0, 'statistic_writes' => 0, 'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED'];
        foreach (Files::rows($manifest['files']['candidates.jsonl']['path']) as $group) {
            $summary['races']++;
            $summary['candidates'] += count($group['candidates']);
        }
        if ($summary['races'] !== $manifest['races']) {
            throw new RuntimeException('FIXED_RACE_DENOMINATOR_CHANGED');
        }
        if (! $dryRun && (file_exists($output) || is_link($output))) {
            throw new RuntimeException('OUTPUT_COLLISION_OR_INVALID_PATH');
        }
        $failed = $outputFailed = false;
        $runError = null;
        $seen = 0;
        $seenRaces = 0;
        $oldDisk = config('keirin.raw_disk');
        $oldRawConfig = config('filesystems.disks.stat21_raw');
        $name = 'stat21_fixed_input';
        if (isset(DB::getConnections()[$name])) {
            throw new RuntimeException('BATCH_CONNECTION_ALREADY_IN_USE');
        }
        $previousConfig = config('database.connections.'.$name);
        try {
            if (! $dryRun) {
                try {
                    Files::directory($output);
                    $this->writer->open($output);
                } catch (Throwable $e) {
                    $failed = $outputFailed = true;
                    $runError = ['exception' => $e::class, 'reason' => 'OUTPUT_OPEN_FAILED'];
                }
            }
            $connection = DB::build(['driver' => 'sqlite', 'database' => 'file:'.$manifest['sqlite']['path'].'?mode=ro', 'prefix' => '', 'foreign_key_constraints' => false]);
            $connection->statement('PRAGMA query_only=ON');
            if ((int) $connection->selectOne('PRAGMA query_only')->query_only !== 1) {
                throw new RuntimeException('SQLITE_NOT_READ_ONLY');
            }
            DB::extend($name, fn () => $connection);
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $manifest['sqlite']['path']]]);
            DB::usingConnection($name, function () use ($manifest, &$summary, &$seen, &$seenRaces, &$failed, &$outputFailed, &$runError, $dryRun): void {
                foreach (Files::rows($manifest['files']['candidates.jsonl']['path']) as $group) {
                    $rows = [];
                    foreach ($group['candidates'] as $c) {
                        $reason = $c['reason'];
                        $status = $failed ? 'NOT_PROCESSED_AFTER_FAILURE' : ($reason === null ? 'PROJECTED' : 'EXCLUDED');
                        if ($outputFailed && $seen === 0 && $rows === []) {
                            $status = 'FAILED_TECHNICAL';
                            $reason = 'OUTPUT_OPEN_FAILED';
                        }
                        $projection = null;
                        if ($status === 'PROJECTED') {
                            try {
                                Files::verify($c['raw']);
                                config(['keirin.raw_disk' => 'stat21_raw', 'filesystems.disks.stat21_raw' => ['driver' => 'local', 'root' => $c['raw_root'], 'throw' => true]]);
                                Storage::forgetDisk('stat21_raw');
                                $projection = $this->projection->project(Race::query()->findOrFail($c['race_id']), RaceEntry::query()->findOrFail($c['entry_id']));
                                Store::binding($c, $projection);
                                Files::verify($c['raw']);
                                $summary['calculated']++;
                            } catch (Throwable $e) {
                                $failed = true;
                                $status = $e instanceof RuntimeException ? 'FAILED_INTEGRITY' : 'FAILED_TECHNICAL';
                                $reason = $e::class;
                                $runError = ['exception' => $e::class, 'reason' => 'PROJECTION_FAILED', 'candidate_key' => $c['key'],
                                    'message' => $e instanceof RuntimeException ? $e->getMessage() : 'TECHNICAL_EXCEPTION'];
                            }
                        }
                        $rows[] = ['candidate' => $c, 'status' => $status, 'reason' => $reason, 'projection' => $projection];
                    }
                    $raceCounts = array_count_values(array_column($rows, 'status'));
                    if (! $dryRun && ! $outputFailed) {
                        $index = 0;
                        try {
                            foreach ($rows as $index => $row) {
                                if ($row['status'] === 'PROJECTED') {
                                    $this->writer->write('projections', ['contract' => Files::VERSION, 'candidate_key' => $row['candidate']['key'], 'projection' => $row['projection']]);
                                    $summary['projection_rows_written']++;
                                }
                                $this->writer->write('candidate-inventory', array_diff_key($row, ['projection' => true]));
                            }
                            $this->writer->write('race-coverage', array_diff_key($group, ['candidates' => true]) + ['statuses' => $raceCounts]);
                        } catch (OutputFailure $e) {
                            $failed = $outputFailed = true;
                            $runError = ['exception' => $e::class, 'reason' => 'CANDIDATE_OUTPUT_FAILED', 'candidate_key' => $rows[$index]['candidate']['key']];
                            // No candidate in this race is committed until its race record is written.
                            foreach ($rows as $i => &$row) {
                                $row['status'] = $i === $index ? 'FAILED_TECHNICAL' : 'NOT_PROCESSED_AFTER_FAILURE';
                                $row['reason'] = $i === $index ? 'CANDIDATE_OUTPUT_FAILED' : 'UNCOMMITTED_RACE_AFTER_OUTPUT_FAILURE';
                            }
                            unset($row);
                            $raceCounts = array_count_values(array_column($rows, 'status'));
                        }
                    }
                    foreach ($rows as $row) {
                        $this->count($summary, $row, $dryRun);
                        $seen++;
                    }
                    $raceStatus = isset($raceCounts['FAILED_INTEGRITY']) || isset($raceCounts['FAILED_TECHNICAL']) ? 'FAILED'
                        : (isset($raceCounts['NOT_PROCESSED_AFTER_FAILURE']) ? 'NOT_PROCESSED_AFTER_FAILURE'
                            : (isset($raceCounts['PROJECTED']) ? 'PROJECTED' : 'EXCLUDED'));
                    $summary['race_statuses'][$raceStatus] = ($summary['race_statuses'][$raceStatus] ?? 0) + 1;
                    $seenRaces++;
                }
            });
            Store::input($input, $sha);
        } catch (Throwable $e) {
            $failed = true;
            $runError = ['exception' => $e::class, 'reason' => 'SEALED_RUN_INPUT_OR_OUTPUT_FAILURE'];
            $remaining = $summary['candidates'] - $seen;
            if ($remaining > 0) {
                $summary['failed']++;
                $summary['not_processed'] += $remaining - 1;
            }
            $remainingRaces = $summary['races'] - $seenRaces;
            if ($remainingRaces > 0) {
                $summary['race_statuses']['FAILED'] = ($summary['race_statuses']['FAILED'] ?? 0) + 1;
                $summary['race_statuses']['NOT_PROCESSED_AFTER_FAILURE'] = ($summary['race_statuses']['NOT_PROCESSED_AFTER_FAILURE'] ?? 0) + $remainingRaces - 1;
            }
        } finally {
            DB::purge($name);
            DB::forgetExtension($name);
            config(['database.connections.'.$name => $previousConfig, 'keirin.raw_disk' => $oldDisk, 'filesystems.disks.stat21_raw' => $oldRawConfig]);
            Storage::forgetDisk('stat21_raw');
            $this->writer->close();
        }
        $summary += ['exit_code' => $failed ? 1 : 0, 'memory_limit' => ini_get('memory_limit'),
            'peak_memory_bytes' => memory_get_peak_usage(true), 'duration_seconds' => microtime(true) - $start, 'run_error' => $runError];
        if (! $dryRun) {
            if (! $outputFailed) {
                try {
                    $this->writer->finish($output, $summary, Files::seal($input));
                } catch (OutputFailure $e) {
                    $summary['exit_code'] = 1;
                    $summary['run_error'] = ['exception' => $e::class, 'reason' => 'OUTPUT_FINALIZATION_FAILED'];
                    $outputFailed = true;
                }
            }
            if ($outputFailed) {
                $summary['failure_diagnostic_saved'] = $this->writer->failure($output, $summary);
            }
        }

        return $summary;
    }

    private function count(array &$summary, array $row, bool $dryRun): void
    {
        $status = $row['status'];
        $candidate = $row['candidate'];
        $counter = match ($status) {
            'PROJECTED' => 'projected', 'EXCLUDED' => 'excluded', 'NOT_PROCESSED_AFTER_FAILURE' => 'not_processed', default => 'failed'
        };
        $summary[$counter]++;
        $year = substr($candidate['race_date'], 0, 4);
        $summary['years'][$year][$status] = ($summary['years'][$year][$status] ?? 0) + 1;
        if ($row['reason'] !== null) {
            $summary['reasons'][$row['reason']] = ($summary['reasons'][$row['reason']] ?? 0) + 1;
        }
        if ($status === 'PROJECTED') {
            $summary['saved'] += $dryRun ? 0 : 1;
            $summary[$candidate['existing_w45'] ? 'w45_projected' : 'additional_projected']++;
            $summary['raw_bytes'] += $candidate['raw']['bytes'];
            foreach ($row['projection']['raw_fields'] as $field => $value) {
                $summary['fields'][$field][$value['state']] = ($summary['fields'][$field][$value['state']] ?? 0) + 1;
            }
        }
    }
}
