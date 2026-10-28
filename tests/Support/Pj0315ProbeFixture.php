<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\ScopeAttempt;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Target;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Factory;

/** Entirely synthetic 2025 test material; never evidence of a successful real HTTP request. */
final class Pj0315ProbeFixture
{
    public static function scope(string $parent): ScopeAttempt
    {
        $path = $parent.'/scope';
        if (! mkdir($path, 0700)) {
            throw new \RuntimeException('Cannot create synthetic scope.');
        }
        Contract::writeNew($path, 'scope.json', json_encode(ScopeAttempt::definition(), JSON_THROW_ON_ERROR));

        return new ScopeAttempt($path);
    }

    public static function html(): string
    {
        return str_replace(['20260616', '"selKjyoCd":"56"'], ['20250104', '"selKjyoCd":"28"'],
            file_get_contents(dirname(__DIR__).'/Fixtures/Keirin/synthetic/race-sync-pj0315.html'));
    }

    public static function target(): Target
    {
        return new Target(['race_id' => 901, 'race_date' => '2025-01-04', 'track_code' => '28', 'race_number' => 1],
            array_map(fn ($bike) => ['bike_number' => $bike, 'external_player_id' => sprintf('%06d', $bike)], range(1, 7)),
            ['disp' => 'PJ0315', 'encp' => 'SYNTHETIC_NOT_REAL'], ['source' => 'SYNTHETIC_TEST_ONLY']);
    }

    public static function saved(string $root): array
    {
        $target = self::target();
        $raw = self::html();
        Contract::writeNew($root, 'baseline.html', $raw);
        $seal = ['bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
        $document = ['cases' => [['target' => ['race' => $target->race, 'entries' => $target->entries],
            'request' => [...$target->race, 'source' => 'keirin_jp', 'external_race_id' => '28:20250104:01',
                'encrypted_parameter' => $target->parameters['encp']],
            'log' => ['id' => 123, 'race_id' => 901, 'request_method' => 'POST', 'request_url' => Target::URI,
                'request_parameters' => $target->parameters, 'source' => 'keirin_jp', 'content_type' => 'text/html; charset=UTF-8',
                'sha256' => $seal['sha256'], 'response_size' => $seal['bytes']], 'fixture_file' => 'baseline.html', 'original' => $seal]]];
        $bytes = json_encode($document, JSON_THROW_ON_ERROR);
        Contract::writeNew($root, 'target.json', $bytes);

        return [$root.'/target.json', hash('sha256', $bytes)];
    }

    public static function fake(Factory $http, string $body, int $status = 200, ?string $uri = Target::URI, ?array &$options = null): void
    {
        $http->preventStrayRequests();
        $http->fake(function ($request, $settings) use ($body, $status, $uri, &$options) {
            $options = $settings;
            if ($uri !== null) {
                $settings['on_stats'](new TransferStats(new Request('POST', $uri), new Response($status), 0.001));
            }

            return Factory::response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
    }
}
