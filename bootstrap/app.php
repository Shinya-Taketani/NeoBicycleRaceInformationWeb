<?php

use App\Console\Commands\Keirin\BackfillRetiredPlayersCommand;
use App\Console\Commands\Keirin\Bt02PreflightCommand;
use App\Console\Commands\Keirin\Bt02SignalEvaluationCommand;
use App\Console\Commands\Keirin\Bt03eHistoricalForwardScoringCommand;
use App\Console\Commands\Keirin\Bt03PreflightCommand;
use App\Console\Commands\Keirin\BuildBt01BaselineCommand;
use App\Console\Commands\Keirin\BuildStat01FeaturesCommand;
use App\Console\Commands\Keirin\ImportRaceResultsCommand;
use App\Console\Commands\Keirin\SyncPlayersCommand;
use App\Console\Commands\Keirin\SyncRaceScheduleCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ImportRaceResultsCommand::class,
        BackfillRetiredPlayersCommand::class,
        SyncRaceScheduleCommand::class,
        SyncPlayersCommand::class,
        BuildStat01FeaturesCommand::class,
        BuildBt01BaselineCommand::class,
        Bt02PreflightCommand::class,
        Bt02SignalEvaluationCommand::class,
        Bt03PreflightCommand::class,
        Bt03eHistoricalForwardScoringCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
