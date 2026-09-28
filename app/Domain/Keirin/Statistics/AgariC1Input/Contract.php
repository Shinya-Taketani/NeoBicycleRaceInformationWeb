<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Input;

use App\Console\Commands\Keirin\BuildAgariC1InputCommand;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Exact;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\History;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use RuntimeException;

final class Contract
{
    public const VERSION = 'STAT35-C1-INPUT-v2-PR75-CONTEXT-VERIFICATION';

    public const C1_VERSION = 'TACTICAL-HISTORY-01-120D-PRE-MEETING-v2';

    public const CONTEXT_VERSION = 'STAT35-C1-ENTRY-CONTEXT-v1';

    public const YEARS = [2022, 2023, 2024, 2025];

    public const ENTRY_KEYS = ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'signals', 'history', 'history_status'];

    public const DEFINITION = 'keirin-jp-final-back-half-lap-v1';

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'years' => self::YEARS, 'format' => 'OUTCOME_FREE_C1_PLUS_SEPARATE_SIDECAR',
            'feature' => 'stat35_mean6', 'window' => 6, 'conversion' => 'RATIONAL_DECIMAL12_HALF_EVEN_FLOAT-v1',
            'historical_as_of_available' => false, 'use' => 'DEVELOPMENT_INPUT_PREPARATION_ONLY',
            'training_evaluation_authorized' => false, 'source_training_fields_discarded' => ['labels', 'rank', 'status']];
    }

    public static function keys(mixed $value, array $keys): void
    {
        if (! is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []) {
            throw new RuntimeException('Unexpected object fields.');
        }
    }

    public static function date(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public static function id(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach ([BuildAgariC1InputCommand::class, Files::class, Classification::class, Exact::class, History::class,
            \App\Domain\Keirin\Statistics\AgariPlayerHistory\Contract::class, Artifacts::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('vendor/brick/math/src'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        $paths[] = base_path('composer.lock');
        sort($paths);
        $code = [];
        foreach ($paths as $path) {
            $code[str_replace(base_path().'/', '', $path)] = hash_file('sha256', $path);
        }

        return $code;
    }
}
