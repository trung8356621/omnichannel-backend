<?php

use App\Http\Controllers\Api\WpBridgeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/wp-bridge/refresh-key', [WpBridgeController::class, 'refreshKey']);

Route::middleware(['auth:sanctum'])->post('/wp-bridge/sync-site-data', [WpBridgeController::class, 'syncSiteData']);