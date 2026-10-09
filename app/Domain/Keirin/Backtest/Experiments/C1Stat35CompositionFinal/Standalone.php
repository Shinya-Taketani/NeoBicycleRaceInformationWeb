<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;
use Symfony\Component\Process\Process;

final class Standalone
{
    public function verify(string $artifact, string $input, string $output, bool $public = false): array
    {
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::swap(new class {public function __call($name, $args): never {throw new RuntimeException('Standalone DB forbidden.');}});
Illuminate\Support\Facades\Http::fake(static fn () => throw new RuntimeException('Standalone HTTP forbidden.'));
$package = $app->make(App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package::class);
$models = $argv[4] === 'public' ? $package->load($argv[1]) : $package->prepared($argv[1]);
$forward = $app->make(App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward::class);
$rows = (function () use ($argv, $models, $forward) {
    foreach (App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input::read($argv[2]) as $race) {
        yield $forward->predict($race, $models['c1'], $models['c2']);
    }
})();
$seal = App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact::write($argv[3], $rows);
$argv[4] === 'public' ? $package->load($argv[1]) : $package->prepared($argv[1]);
echo json_encode(['seal'=>$seal,'pid'=>getmypid(),'memory_limit'=>ini_get('memory_limit'),'peak_bytes'=>memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n";
PHP;
        $allowed = [base_path(), dirname($artifact), $input, $input.'.manifest.json', $input.'.input.json', dirname($output), sys_get_temp_dir()];
        $argv = [PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'open_basedir='.implode(PATH_SEPARATOR, $allowed), '-r', $script, $artifact, $input, $output, $public ? 'public' : 'prepared'];
        $process = new Process($argv, base_path(), [
            'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => base_path('bootstrap/cache/composition-no-config.php'),
            'APP_ROUTES_CACHE' => base_path('bootstrap/cache/composition-no-routes.php'),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr',
        ]);
        $process->setTimeout(null);
        $process->run();
        JsonlArtifact::json($output.'.process.json',
            ['exit_code' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(), 'memory_limit' => '128M']);
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('Isolated package/input-only prediction failed: '.$process->getErrorOutput());
        }
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        if (($result['memory_limit'] ?? null) !== '128M' || ($result['pid'] ?? null) === getmypid() || $result['peak_bytes'] > 134217728) {
            throw new RuntimeException('Invalid isolated 128M verification.');
        }
        Files::verify($output, $result['seal']);

        return $result + ['exit_code' => $process->getExitCode(), 'source_access' => 'OS_OPEN_BASEDIR_PACKAGE_FEATURE_INPUT_REPOSITORY_ONLY'];
    }
}
