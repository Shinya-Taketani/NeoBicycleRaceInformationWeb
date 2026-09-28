<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Context;

use App\Console\Commands\Keirin\BuildAgariC1ContextCommand;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\AgariC1Input\SourceProjector;
use App\Domain\Keirin\Statistics\AgariC1Input\Sources;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\ConnectionGuard;
use RuntimeException;

final class Contract
{
    public const VERSION = 'STAT35-C1-CONTEXT-01-v1';

    public const C1_DIRECTORY = '/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/inputs-v2';

    public const TARGET_FIELDS = ['race_id', 'entry_id', 'bike', 'race_date', 'meeting_id', 'meeting_start', 'meeting_end', 'player_id', 'feature_input_hash'];

    public const COLUMNS = [
        'entry' => ['id', 'race_id', 'player_id', 'external_player_id', 'bike_number', 'fetched_at'],
        'race' => ['id', 'source', 'external_race_id', 'race_day_id', 'race_date', 'race_number', 'race_type'],
        'day' => ['id', 'race_meeting_id', 'race_date'],
        'meeting' => ['id', 'source', 'starts_on', 'ends_on'],
    ];

    public const TABLES = ['entry' => 'race_entries', 'race' => 'races', 'day' => 'race_days', 'meeting' => 'race_meetings'];

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'years' => InputContract::YEARS, 'source' => 'keirin_jp',
            'status' => 'REVIEW_PENDING', 'historical_as_of_available' => false, 'observed_at' => null,
            'c1_directory' => self::C1_DIRECTORY, 'c1_manifest' => Sources::PINS['c1'],
            'columns' => self::COLUMNS, 'tables' => self::TABLES, 'chunk_entries' => 500,
            'extract_authorization_required' => true, 'offline_build_and_reproduce' => true,
            'automatic_context_acceptance' => false, 'mean6_generation' => false];
    }

    public static function target(array $target, int $year): void
    {
        InputContract::keys($target, self::TARGET_FIELDS);
        if (! in_array($year, InputContract::YEARS, true) || ! InputContract::id($target['race_id'])
            || ! InputContract::id($target['entry_id']) || ! InputContract::id($target['bike']) || $target['bike'] > 9
            || ! InputContract::date($target['race_date']) || (int) substr($target['race_date'], 0, 4) !== $year
            || ($target['player_id'] !== null && ! InputContract::id($target['player_id']))
            || ! is_string($target['feature_input_hash']) || $target['feature_input_hash'] === '') {
            throw new RuntimeException('Invalid or unauthorized fixed target.');
        }
    }

    public static function output(string $output, array $sources): void
    {
        $parent = realpath(dirname($output));
        if ($parent === false || basename($output) === '.' || basename($output) === '..') {
            throw new RuntimeException('An existing output parent is required.');
        }
        foreach ($sources as $source) {
            $root = realpath($source);
            if ($root !== false && ($parent === $root || str_starts_with($parent.'/', $root.'/'))) {
                throw new RuntimeException('Output overlaps source evidence.');
            }
        }
        Artifacts::create($output);
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach ([BuildAgariC1ContextCommand::class, InputContract::class, SourceProjector::class, Sources::class,
            Stream::class, Validator::class, Files::class, Classification::class, Artifacts::class, ConnectionGuard::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        $paths[] = base_path('composer.lock');
        foreach (['app/Repositories/RaceRepository.php', 'app/Domain/Keirin/Scraping/Parsers/RaceEntryListParser.php',
            'app/Domain/Keirin/Scraping/Parsers/RaceDetailParser.php'] as $path) {
            $paths[] = base_path($path);
        }
        sort($paths);
        $code = [];
        foreach ($paths as $path) {
            $code[str_replace(base_path().'/', '', $path)] = hash_file('sha256', $path);
        }

        return $code;
    }

    public static function published(string $directory, string $kind): array
    {
        Files::verify($directory.'/manifest.json', Files::json($directory.'/COMPLETE.json'));
        $manifest = Files::json($directory.'/manifest.json');
        if (($manifest['version'] ?? null) !== self::VERSION || ($manifest['kind'] ?? null) !== $kind
            || ($manifest['status'] ?? null) !== 'REVIEW_PENDING' || ($manifest['historical_as_of_available'] ?? null) !== false) {
            throw new RuntimeException('Unknown context artifact contract.');
        }
        InputContract::keys($manifest['files'] ?? [], match ($kind) {
            'EXTRACTION' => ['targets.jsonl', 'db-records.jsonl', 'queries.jsonl', 'connection.json'],
            'MAPPING' => ['candidate/entry-context.jsonl', 'mapping-audit.jsonl', 'samples.jsonl', 'summary.json',
                'provenance.json', 'candidate/manifest.json', 'candidate/COMPLETE.json'],
            default => throw new RuntimeException('Unknown artifact kind.'),
        });
        if ($kind === 'EXTRACTION') {
            Files::same(self::COLUMNS, $manifest['columns'] ?? [], 'extraction columns');
            Files::same(self::TABLES, $manifest['tables'] ?? [], 'extraction tables');
            Files::same($manifest['source']['target_seal'] ?? [], $manifest['files']['targets.jsonl'], 'target seal');
        }
        foreach ($manifest['files'] as $name => $seal) {
            if (basename($name) !== $name && ! in_array($name, ['candidate/entry-context.jsonl', 'candidate/manifest.json', 'candidate/COMPLETE.json'], true)) {
                throw new RuntimeException('Unsafe evidence path.');
            }
            Files::verify($directory.'/'.$name, $seal);
        }

        return $manifest;
    }
}
