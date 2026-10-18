<?php

use App\Http\Controllers\Api\SearchController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::get('/search', SearchController::class)
    ->middleware([AuthenticateApiKey::class, 'throttle:api-key'])
    ->name('api.search');
