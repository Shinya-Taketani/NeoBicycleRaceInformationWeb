<?php

use App\Http\Controllers\Development\CompositionArchiveController;
use App\Http\Controllers\Development\CompositionResultController;
use App\Http\Middleware\LocalCompositionArchiveAccess;
use App\Http\Middleware\LocalCompositionViewAccess;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('development/keirin/composition-results')
    ->middleware(LocalCompositionViewAccess::class)
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->group(function (): void {
        Route::get('/', [CompositionResultController::class, 'index'])->name('development.composition-results.index');
        Route::get('/{raceId}', [CompositionResultController::class, 'show'])->name('development.composition-results.show');
    });

Route::prefix('development/keirin/composition-archive/2025')
    ->middleware(LocalCompositionArchiveAccess::class)
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->group(function (): void {
        Route::get('/', [CompositionArchiveController::class, 'index'])->name('development.composition-archive.index');
        Route::get('/{raceId}', [CompositionArchiveController::class, 'show'])->name('development.composition-archive.show');
    });
