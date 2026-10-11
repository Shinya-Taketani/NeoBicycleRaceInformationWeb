<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\TacticalInputReadiness;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Context\Contract as Context;
use App\Domain\Keirin\Statistics\AgariC1Context\ReadOnlySession;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\ConnectionGuard;
use RuntimeException;

final class Contract
{
    public const VERSION = 'C1-TACTICAL-INPUT-READINESS-v1';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/c1-tactical-input-readiness-01';

    public const YEARS = [2022, 2023, 2024, 2025];

    public const STYLES = ['逃' => '逃', '追' => '追', '両' => '両'];

    public const RECORD_KEYS = ['entry_id', 'race_id', 'bike', 'player_id', 'external_player_id', 'race_date',
        'scheduled_start_at', 'riding_style', 'line_text', 'fetched_at'];

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'years' => self::YEARS, 'sources' => Universe::FIXED,
            'root' => self::ROOT, 'modes' => ['plan', 'audit', 'build', 'reproduce'],
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED',
            'training_evaluation_authorized' => false, '2026' => 'FORBIDDEN',
            'styles' => self::STYLES, 'line_interpretation' => 'UNCONFIRMED_NO_ROLE_INFERENCE',
            'timing' => 'GENERIC_FETCHED_AT_IS_NOT_FIELD_OBSERVATION_EVIDENCE',
            'accuracy_improvement' => 'NOT_MEASURED'];
    }

    public static function target(array $target, int $year): void
    {
        Context::target($target, $year);
        if (! C1::date($target['meeting_start']) || ! C1::date($target['meeting_end'])
            || $target['meeting_start'] > $target['race_date'] || $target['meeting_end'] < $target['race_date']) {
            throw new RuntimeException('Invalid fixed meeting dates.');
        }
    }

    public static function output(string $path, array $sources): void
    {
        if (! str_starts_with($path, '/') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//|/$~', $path)
            || realpath(dirname($path)) !== dirname($path)) {
            throw new RuntimeException('Canonical absolute output with an existing parent required.');
        }
        foreach ($sources as $source) {
            if ($path === $source || str_starts_with($path.'/', $source.'/') || str_starts_with($source.'/', $path.'/')) {
                throw new RuntimeException('Output overlaps source.');
            }
        }
        Artifacts::create($path);
    }

    public static function code(): array
    {
        $paths = [...glob(__DIR__.'/*.php'), base_path('app/Console/Commands/Keirin/TacticalInputReadinessCommand.php')];
        foreach ([Files::class, Context::class, C1::class, Artifacts::class,
            Stream::class,
            Validator::class,
            ReadOnlySession::class,
            ConnectionGuard::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        foreach (['composer.lock', 'app/Repositories/RaceRepository.php',
            'app/Domain/Keirin/Scraping/Parsers/RaceDetailParser.php',
            'app/Domain/Keirin/Scraping/Parsers/RaceEntryListParser.php'] as $relative) {
            $paths[] = base_path($relative);
        }
        sort($paths, SORT_STRING);
        $seals = [];
        foreach (array_unique($paths) as $path) {
            $seals[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }

        return $seals;
    }

    public static function published(string $path, string $kind): array
    {
        Files::verify($path.'/manifest.json', Files::json($path.'/COMPLETE.json'));
        $m = Files::json($path.'/manifest.json');
        if (($m['version'] ?? null) !== self::VERSION || ($m['kind'] ?? null) !== $kind
            || ($m['historical_as_of_available'] ?? null) !== false || ($m['prediction_use'] ?? null) !== 'NOT_AUTHORIZED') {
            throw new RuntimeException('Unknown readiness bundle.');
        }
        $names = $kind === 'AUDIT' ? ['targets.jsonl', 'records.jsonl', 'source-audit.json', 'queries.jsonl']
            : ['contract.json', 'coverage.json', 'category-inventory.json', ...array_map(fn ($y) => 'tactical-input-'.$y.'.jsonl', self::YEARS)];
        C1::keys($m['files'] ?? null, $names);
        foreach ($m['files'] as $name => $seal) {
            Files::verify($path.'/'.$name, $seal);
        }

        return $m;
    }
}
