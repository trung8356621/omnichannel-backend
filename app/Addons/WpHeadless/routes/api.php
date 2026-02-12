<?php

use App\Addons\WpHeadless\Http\Controllers\Api\WpBridgeController;
use Illuminate\Support\Facades\Route;

Route::post('/wp-bridge/refresh-key', [WpBridgeController::class, 'refreshKey']);
Route::post('/wp-bridge/sync-site-data', [WpBridgeController::class, 'syncSiteData']);
