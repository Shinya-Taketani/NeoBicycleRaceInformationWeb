<?php

declare(strict_types=1);

use App\Console\Commands\Keirin\CompositionPredictionResultCommand;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Service;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\Support\CompositionResultFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = CompositionResultFixture::environment();
$args = Files::json($argv[1]);
$publication = CompositionResultFixture::publication($args['root']);
if (($args['hold_lock'] ?? false) === true) {
    $destination = $args['output'].'/.guards/synthetic-01';
    $lock = $publication->acquire($publication->destination($destination));
    file_put_contents($args['root'].'/LOCK_READY', 'ready');
    usleep(1500000);
    fclose($lock);
    exit(0);
}
$service = CompositionResultFixture::connect($args['root'], $args['labels'], $args['seal'], $args['races'], $publication);
$app->instance(Service::class, $service);
$command = new CompositionPredictionResultCommand;
$command->setLaravel($app);
$console = new Application;
$console->setAutoExit(false);
$console->addCommand($command);
exit($console->run(new ArrayInput([
    'command' => 'keirin:c1:composition-result', 'mode' => $args['mode'] ?? 'execute',
    '--evaluation-id' => 'synthetic-01', '--output-root' => $args['output'],
    '--request-store-root' => $args['request_root'], '--requests' => $args['selection'], '--json' => true,
])));
