<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PalaceController;
use App\Http\Controllers\WikiController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');

// Auth routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Authenticated routes
Route::middleware('auth')->group(function () {
    // Wiki
    Route::get('/wiki', [WikiController::class, 'index'])->name('wiki.index');
    Route::get('/wiki/search', [WikiController::class, 'search'])->name('wiki.search');
    Route::get('/wiki/{name}', [WikiController::class, 'show'])->name('wiki.show')
        ->where('name', '[a-z0-9:_-]+');
    Route::get('/wiki/{name}/history', [WikiController::class, 'history'])->name('wiki.history')
        ->where('name', '[a-z0-9:_-]+');

    // Palace
    Route::get('/palace', [PalaceController::class, 'index'])->name('palace.index');
    Route::get('/palace/drawer/{drawer}', [PalaceController::class, 'drawer'])->name('palace.drawer');
    Route::get('/palace/{wing}', [PalaceController::class, 'wing'])->name('palace.wing');
    Route::get('/palace/{wing}/{room}', [PalaceController::class, 'room'])->name('palace.room');
});
