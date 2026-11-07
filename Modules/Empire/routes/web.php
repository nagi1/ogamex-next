<?php

use Illuminate\Support\Facades\Route;
use Modules\Empire\Http\Controllers\DiplomacyController;

Route::middleware(['auth', 'banned', 'globalgame', 'locale', 'firstlogin'])
    ->name('empire.')
    ->group(function (): void {
        Route::get('/ajax/empire/diplomacy', [DiplomacyController::class, 'tab'])->name('diplomacy.tab');
        Route::post('/empire/diplomacy/{act}', [DiplomacyController::class, 'act'])
            ->whereIn('act', DiplomacyController::ACTS)->name('diplomacy.act');
    });
