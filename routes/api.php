<?php

use App\Http\Controllers\McpController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('mcp')->group(function () {
    Route::get('/tools', [McpController::class, 'tools']);

    Route::middleware(AuthenticateApiKey::class)->group(function () {
        Route::post('/call', [McpController::class, 'call']);
    });
});
