<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\FrontendProxyController;
use Illuminate\Support\Facades\Route;

// Routes mặc định (Breeze)
Route::get('/', function () {
    return '123';
});

// Google Auth Routes
Route::get('auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login');
Route::get('auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);

// Proxy Laravel → Next.js/React (FrontendProject: router + proxy_auto + port)
Route::middleware('web')
    ->get('/frontend/{router}/{path?}', FrontendProxyController::class)
    ->where('path', '.*')
    ->name('frontend.proxy');
