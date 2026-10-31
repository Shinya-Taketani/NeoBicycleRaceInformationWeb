<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class SavedWeatherResultFixture
{
    public const SHA256 = '706e5747f6383711055d1ded3c16575016cfc22148823cc75379117410ee3f11';

    public const DEFAULT_PATH = '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/pr117-review-20261022-075425-15b42fa7/result-fixture.json';

    public static function path(): string
    {
        $explicit = getenv('STAT38_RESULT_FIXTURE');
        $path = $explicit === false || $explicit === '' ? self::DEFAULT_PATH : $explicit;
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Saved weather result fixture is missing or unreadable.');
        }
        if (hash_file('sha256', $path) !== self::SHA256) {
            throw new RuntimeException('Saved weather result fixture SHA mismatch.');
        }

        return $path;
    }
}
