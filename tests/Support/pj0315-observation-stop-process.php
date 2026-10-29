<?php

declare(strict_types=1);

use App\Domain\Keirin\Scraping\PrestartCapture\Observation\AttemptLedger;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationCaptureService;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationManifest;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationPreflight;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationVerifier;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\Pj0315ObservationTransport;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Tests\Support\ObservationRunnerFixture;
use Tests\Support\Pj0315ProbeFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Synthetic fixture only. Exit at an exact durable boundary, never by a catchable exception or a timing race.
if (ini_get('memory_limit') !== '128M') {
    throw new RuntimeException('Independent test requires 128M.');
}
[$script, $file, $sha, $action] = $argv;
$data = ObservationContract::readDocument($file, $sha);
$manifest = new ObservationManifest($data['manifest']);
$root = dirname($file);
if ($action === 'verify') {
    try {
        $result = (new ObservationVerifier)->verify($argv[4], $argv[5]);
    } catch (RuntimeException $error) {
        $result = ['status' => 'BLOCKED', 'reason' => $error->getMessage()];
    }
    echo json_encode(['result' => $result, 'memory_limit' => ini_get('memory_limit'),
        'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
$http = new Factory;
$raw = match ($data['variation']) {
    'roster' => str_replace('000001', '888888', Pj0315ProbeFixture::html()),
    'schedule' => str_replace('10:45', '10:46', Pj0315ProbeFixture::html()),
    default => Pj0315ProbeFixture::html(),
};
$timeoutCalls = 0;
if ($data['variation'] === 'timeout') {
    $http->fake(function () use (&$timeoutCalls) {
        $timeoutCalls++;
        throw new ConnectionException('cURL error 28: synthetic timeout');
    });
} else {
    Pj0315ProbeFixture::fake($http, $raw, $data['variation'] === 'http429' ? 429 : 200);
}
$ledger = $action === 'interrupt' ? new class($manifest, $data['registry'], $root, $http) extends AttemptLedger
{
    public function __construct(ObservationManifest $manifest, string $registry, private readonly string $root, private readonly Factory $http)
    {
        parent::__construct($manifest, $registry);
    }

    public function stop(string $reason, string $at): void
    {
        ObservationContract::write($this->root, 'boundary.json', ['boundary' => 'AFTER_OUTCOME_BEFORE_STOP',
            'snapshot' => $this->snapshot(), 'stop_reason' => $reason, 'http_calls' => $this->http->recorded()->count(),
            'memory_limit' => ini_get('memory_limit'), 'peak_memory_bytes' => memory_get_peak_usage(true)]);
        exit(73);
    }
} : new AttemptLedger($manifest, $data['registry']);
$input = $data['input'];
$slot = in_array($action, ['resume', 'reserve'], true) ? 2 : 1;
$at = $slot === 2 ? '2025-01-04T01:30:00Z' : '2025-01-04T00:45:00Z';
ObservationRunnerFixture::time($input, $at, $slot);
$evaluation = new DateTimeImmutable($at);
$preflight = (new ObservationPreflight)->evaluate($manifest, 901, $slot, $input, Contract::SIMULATED, $evaluation);
try {
    if ($action === 'reserve') {
        $reservation = $ledger->exclusive(fn ($ledger) => $ledger->reserve(901, $slot, $at));
        $result = ['status' => 'RESERVED', 'reservation' => $reservation];
    } else {
        $result = (new ObservationCaptureService(new Pj0315ObservationTransport($http)))->run($manifest, 901, $slot, $input,
            Contract::SIMULATED, $evaluation, $ledger, $slot === 2 ? $data['second_output'] : $data['output']);
    }
} catch (RuntimeException $error) {
    $result = ['status' => 'BLOCKED', 'reason' => $error->getMessage()];
}
echo json_encode(['result' => $result, 'preflight_status' => $preflight['status'], 'snapshot' => $ledger->snapshot(),
    'http_calls' => $http->recorded()->count() + $timeoutCalls, 'memory_limit' => ini_get('memory_limit'),
    'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), "\n";
