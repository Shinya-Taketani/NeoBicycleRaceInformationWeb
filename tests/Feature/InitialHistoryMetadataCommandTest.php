<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Exporter;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\PostgresSource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\InitialHistoryMetadataFixture as F;
use Tests\TestCase;

final class InitialHistoryMetadataCommandTest extends TestCase
{
    private CompositionResultTemporaryDirectory $environment;

    public function createApplication(): Application
    {
        $this->environment = CompositionResultTemporaryDirectory::create('/tmp');
        $root = $this->environment->path();
        foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'compiled'] as $name) {
            mkdir($root.'/'.$name, 0700);
        }
        Json::write($root.'/.env', "# Isolated test fixture. No application secrets.\n");
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(self::class);
        $app->useEnvironmentPath($root);
        $app->useBootstrapPath($root.'/bootstrap');
        $app->useStoragePath($root.'/runtime');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set(['logging.default' => 'null', 'view.compiled' => $root.'/compiled']);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_plan_and_verify_artisan_routes_never_connect_to_business_db(): void
    {
        DB::shouldReceive('build')->never();
        DB::shouldReceive('connection')->never();
        $f = new F;
        $this->artisan('keirin:backtest:initial-history-metadata', ['--plan' => true, '--audit-root' => $f->audit(), '--output-root' => $f->root.'/plan'])->assertSuccessful();
        $this->assertSame($this->environment->path().'/.env', $this->app->environmentFilePath());
        (new Exporter($f->source()))->export($f->root.'/plan/scope-manifest.json', $f->root.'/export');
        $this->artisan('keirin:backtest:initial-history-metadata', ['--verify' => true, '--bundle' => $f->root.'/export', '--output-root' => $f->root.'/verify'])->assertSuccessful();
        $this->assertFileExists($f->root.'/verify/COMPLETE.json');
        Http::assertNothingSent();
    }

    public static function invalidArguments(): array
    {
        return [[[]], [['--plan' => true, '--verify' => true]], [['--export' => true]],
            [['--verify' => true, '--bundle' => '/not-used', '--scope-manifest' => '/forbidden', '--output-root' => '/not-used']]];
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_modes_and_cross_mode_arguments_do_not_connect(array $arguments): void
    {
        DB::shouldReceive('build')->never();
        $this->artisan('keirin:backtest:initial-history-metadata', $arguments)->assertFailed();
        Http::assertNothingSent();
    }

    public function test_bad_connection_endpoint_stops_before_any_business_select_and_disconnects(): void
    {
        [$pre] = F::connection();
        $pre['database'] = 'wrong';
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('fetch')->willReturn($pre);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(3))->method('exec');
        $pdo->expects($this->once())->method('query')->willReturn($statement);
        $pdo->expects($this->never())->method('prepare');
        $pdo->method('inTransaction')->willReturn(false);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('disableQueryLog')->once();
        $connection->shouldReceive('setReconnector')->once();
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('disconnect')->once();
        DB::shouldReceive('build')->once()->andReturn($connection);
        $this->expectException(RuntimeException::class);
        (new PostgresSource)->capture(new PDO('sqlite::memory:'), fn () => throw new RuntimeException('Business query forbidden.'), fn () => null);
    }

    public function test_bad_snapshot_stops_before_business_select_and_rolls_back(): void
    {
        [$pre, $snapshot] = F::connection();
        $snapshot['snapshot'] = '';
        $before = $this->createStub(PDOStatement::class);
        $before->method('fetch')->willReturn($pre);
        $inside = $this->createStub(PDOStatement::class);
        $inside->method('fetch')->willReturn($snapshot);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(4))->method('exec');
        $pdo->expects($this->exactly(2))->method('query')->willReturnOnConsecutiveCalls($before, $inside);
        $pdo->expects($this->never())->method('prepare');
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack');
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('disableQueryLog')->once();
        $connection->shouldReceive('setReconnector')->once();
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('disconnect')->once();
        DB::shouldReceive('build')->once()->andReturn($connection);
        $this->expectException(RuntimeException::class);
        (new PostgresSource)->capture(new PDO('sqlite::memory:'), fn () => throw new RuntimeException('Business query forbidden.'), fn () => null);
    }
}
