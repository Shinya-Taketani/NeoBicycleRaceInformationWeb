<?php

declare(strict_types=1);

// Synthetic subprocess only: HTTP fake inside the parent's network-isolated namespace.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Probe;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\ProbeFailure;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\ScopeAttempt;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\SinglePostTransport;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Target;
use Illuminate\Http\Client\Factory;
use Tests\Support\Pj0315ProbeFixture as Fixture;

[$script, $scopePath, $input, $sha, $output, $calls, $id, $barrier] = $argv;
$scope = new ScopeAttempt($scopePath);
$http = new Factory;
$http->preventStrayRequests();
$http->fake(function () use ($calls, $id) {
    Contract::writeNew($calls, 'sent-'.$id.'.json', '{"synthetic_http_fake":true}');
    usleep(50000);

    return Factory::response(Fixture::html(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
});
if ($barrier !== '-') {
    Contract::writeNew($calls, 'ready-'.$id.'.json', '{}');
    $until = hrtime(true) + 5_000_000_000;
    while (! is_file($barrier)) {
        if (hrtime(true) >= $until) {
            throw new RuntimeException('Synthetic start barrier timed out.');
        }
        usleep(1000);
    }
}
try {
    $result = (new Probe(new SinglePostTransport($http), $scope))->run(Target::load($input, $sha), $output, true);
    echo json_encode(['status' => $result['status'], 'scope' => $scope->status(),
        'memory_limit' => ini_get('memory_limit'), 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n";
    exit(0);
} catch (ProbeFailure $failure) {
    echo json_encode($failure->evidence, JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
