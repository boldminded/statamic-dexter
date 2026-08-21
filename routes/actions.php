<?php

use BoldMinded\Dexter\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Auto-prefixed with /!/dexter by Statamic.
//
// GET and POST both accepted: GET suits simple client-side calls, POST keeps
// long filter payloads out of the URL and out of access logs.
Route::match(['get', 'post'], 'search', SearchController::class)->name('search');
