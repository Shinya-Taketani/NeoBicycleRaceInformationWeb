<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountC1Candidate;

use App\Console\Commands\Keirin\StartCountC1CandidateCommand;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Context\Contract as Context;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use RuntimeException;

final class Contract
{
    public const VERSION = 'STAT36-C1-CANDIDATE-v1';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/stat36-c1-candidate-01';

    public const YEARS = [2022, 2023, 2024, 2025];

    public static function restrictions(): array
    {
        return ['artifact_role' => 'REVIEW_CANDIDATE_ONLY', 'prediction_use' => 'NOT_AUTHORIZED',
            'training_evaluation_authorized' => false, 'historical_as_of_available' => false, 'points' => null];
    }

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'years' => self::YEARS, 'status' => 'CANDIDATES_PREPARED_NOT_AUTHORIZED',
            ...self::restrictions(), 'field' => 'candidate_displayed_start_count', 'sources' => Sources::FIXED,
            'versions' => 'ALL_NORMAL_VALUES_MUST_AGREE_INCOMPLETE_OR_CONTRADICTORY_VERSIONS_HOLD',
            'representative' => 'MIN_FETCH_LOG_ID_THEN_ROW_INDEX_REFERENCE_ONLY',
            'timing' => 'UNKNOWN_S_PERIOD_BASELINE_CORRECTION_NO_HISTORICAL_ELIGIBILITY_INFERENCE',
            'access' => 'OFFLINE_NO_DB_HTTP_RAW_TRAINING', '2026' => 'FORBIDDEN'];
    }

    public static function year(mixed $year): void
    {
        if (! in_array($year, self::YEARS, true)) {
            throw new RuntimeException('Unauthorized year, including 2026.');
        }
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach ([StartCountC1CandidateCommand::class, Files::class, C1::class, Context::class, Validator::class, Stream::class, Artifacts::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        $paths[] = base_path('composer.lock');
        sort($paths, SORT_STRING);
        $code = [];
        foreach (array_unique($paths) as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }

        return $code;
    }
}
