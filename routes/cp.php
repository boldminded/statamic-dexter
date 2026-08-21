<?php

use BoldMinded\Dexter\Http\Controllers\DexterController;
use BoldMinded\Dexter\Http\Controllers\IndexActionController;
use Illuminate\Support\Facades\Route;

Route::get('dexter', [DexterController::class, 'index'])->name('dexter.index');

// Mutating actions are POST only, so they cannot be triggered by a link,
// a prefetch, or an <img> tag pointed at the URL. Export writes a file into
// the app's config directory, so it belongs here rather than with the reads.
//
// Reindex takes its target in the body rather than the URL: one dropdown
// lists every source across every index, so the index is part of the choice.
Route::post('dexter/reindex', [IndexActionController::class, 'reindex'])
    ->name('dexter.reindex');

// Import and export take their target in the body for the same reason reindex
// does: the choice is a resolved index name covering one site, not a bare
// config key, so it does not belong in the URL as a route parameter.
Route::post('dexter/import', [IndexActionController::class, 'import'])
    ->name('dexter.import');

Route::post('dexter/export', [IndexActionController::class, 'export'])
    ->name('dexter.export');

// Unmanaged indexes are addressed by their resolved name rather than a config
// key, because by definition no config key maps to them. Kept on separate
// routes so the key-based actions below keep refusing anything outside the
// configured map -- these two are the only way to touch an unmanaged index,
// and only ever to remove it.
//
// Declared BEFORE the {index} routes: Laravel matches in declaration order, so
// dexter/{index}/delete would otherwise swallow dexter/unmanaged/delete with
// $index = 'unmanaged' and 404 on the configured-map check.
Route::post('dexter/unmanaged/clear', [IndexActionController::class, 'clearUnmanaged'])
    ->name('dexter.unmanaged.clear');

Route::post('dexter/unmanaged/delete', [IndexActionController::class, 'destroyUnmanaged'])
    ->name('dexter.unmanaged.delete');

Route::post('dexter/{index}/clear', [IndexActionController::class, 'clear'])
    ->name('dexter.clear');

Route::post('dexter/{index}/delete', [IndexActionController::class, 'destroy'])
    ->name('dexter.delete');
