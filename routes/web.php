<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\GameImageController;
use App\Http\Controllers\IgdbController;
use App\Http\Controllers\LibraryTransferController;
use App\Http\Controllers\McpSettingsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->name('api.')->group(function (): void {
    Route::get('session', [SessionController::class, 'show'])->name('session.show');
    Route::post('session', [SessionController::class, 'store'])->name('session.store');
    Route::delete('session', [SessionController::class, 'destroy'])->name('session.destroy');

    Route::middleware('personal')->group(function (): void {
        Route::get('library', [GameController::class, 'library'])->name('library');
        Route::get('statistics', StatisticsController::class)->name('statistics');
        Route::post('settings/library/export', [LibraryTransferController::class, 'export'])->name('library-transfer.export');
        Route::get('settings/library/export/{id}', [LibraryTransferController::class, 'download'])
            ->whereUuid('id')->name('library-transfer.download');
        Route::post('settings/library/import', [LibraryTransferController::class, 'import'])->name('library-transfer.import');
        Route::get('settings/mcp', [McpSettingsController::class, 'show'])->name('mcp-settings.show');
        Route::patch('settings/mcp', [McpSettingsController::class, 'update'])->name('mcp-settings.update');
        Route::post('settings/mcp/tokens/{access}', [McpSettingsController::class, 'store'])
            ->whereIn('access', ['read', 'write'])->name('mcp-settings.tokens.store');
        Route::delete('settings/mcp/tokens/{access}', [McpSettingsController::class, 'destroy'])
            ->whereIn('access', ['read', 'write'])->name('mcp-settings.tokens.destroy');
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

Route::view('/{path?}', 'app')->where('path', '(?!api/|media/|mcp(?:/|$)).*')->name('app');
