<?php

declare(strict_types=1);
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Predictor;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;

// Router for php -S 127.0.0.1:<port> -t public scripts/composition-archive-server.php.
// The caller supplies an existing dedicated runtime, never the repository's storage/cache.
umask(0077);
$repo = dirname(__DIR__);
require $repo.'/vendor/autoload.php';
$runtime = getenv('C1_ARCHIVE_VIEW_RUNTIME');
if (! is_string($runtime) || $runtime === '' || realpath($runtime) !== $runtime
    || ! str_starts_with($runtime, Contract::ROOT.'/')
    || is_link($runtime)) {
    http_response_code(503);
    exit('Dedicated runtime required.');
}
foreach (['bootstrap/cache', 'storage', 'compiled', 'empty'] as $name) {
    Store::safe($runtime.'/'.$name);
    if (! is_dir($runtime.'/'.$name)) {
        http_response_code(503);
        exit('Dedicated runtime incomplete.');
    }
}
if (in_array(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    ['/css/development-composition-results.css', '/css/development-composition-archive.css'], true)) {
    return false;
}
putenv('APP_ENV=local');
putenv('PAO_DISABLE=1');
$app = require $repo.'/bootstrap/app.php';
$app->useEnvironmentPath($runtime.'/empty');
$app->useBootstrapPath($runtime.'/bootstrap');
$app->useStoragePath($runtime.'/storage');
$request = Request::capture();
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
foreach (['db', 'redis', Factory::class,
    Package::class,
    Forward::class,
    Predictor::class] as $service) {
    $app->bind($service, fn () => throw new RuntimeException('External data / inference forbidden.'));
}
$app['config']->set(['view.compiled' => $runtime.'/compiled', 'logging.default' => 'stderr', 'app.debug' => false,
    'composition_archive_view.enabled' => getenv('C1_COMPOSITION_ARCHIVE_ENABLED') === 'true']);
$response = $kernel->handle($request);
$response->headers->set('X-Development-View-Peak-Bytes', (string) memory_get_peak_usage(true));
$response->send();
$kernel->terminate($request, $response);
