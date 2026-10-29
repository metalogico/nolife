<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\GameImageController;
use App\Http\Controllers\IgdbController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->name('api.')->group(function (): void {
    Route::get('session', [SessionController::class, 'show'])->name('session.show');
    Route::post('session', [SessionController::class, 'store'])->name('session.store');
    Route::delete('session', [SessionController::class, 'destroy'])->name('session.destroy');

    Route::middleware('personal')->group(function (): void {
        Route::get('library', [GameController::class, 'library'])->name('library');
        Route::apiResource('games', GameController::class);
        Route::post('games/{game}/metadata', [IgdbController::class, 'store'])->name('metadata.store');
        Route::post('games/{game}/cover', [GameImageController::class, 'store'])->name('cover.store');
        Route::get('igdb/search', [IgdbController::class, 'index'])->name('igdb.search');
        Route::get('igdb/games/{igdbId}', [IgdbController::class, 'show'])->whereNumber('igdbId')->name('igdb.show');
    });
});

Route::get('media/{image}/{variant?}', [GameImageController::class, 'show'])
    ->whereIn('variant', ['full', 'thumbnail', 'ambient'])
    ->middleware('personal')
    ->name('media.show');

Route::view('/{path?}', 'app')->where('path', '(?!api/|media/).*')->name('app');
