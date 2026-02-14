<?php

use App\Addons\WpHeadless\Http\Controllers\Api\OptimizedCssForUrlController;
use App\Addons\WpHeadless\Http\Controllers\Api\StylesOptimizedController;
use App\Addons\WpHeadless\Http\Controllers\Api\TemplatesController;
use App\Addons\WpHeadless\Http\Controllers\Api\WpBridgeController;
use Illuminate\Support\Facades\Route;

Route::post('/wp-bridge/refresh-key', [WpBridgeController::class, 'refreshKey']);
Route::post('/wp-bridge/sync-site-data', [WpBridgeController::class, 'syncSiteData']);

Route::post('/wp-headless/styles-optimized', [StylesOptimizedController::class, 'store']);
Route::get('/wp-headless/templates', TemplatesController::class);

/** Next.js: gửi url → Laravel lấy data WordPress (GraphQL) + CSS tối ưu, trả về data + optimizedCssUrls. */
Route::post('/wp-headless/page-by-url', OptimizedCssForUrlController::class);
Route::post('/wp-headless/optimized-css-for-url', OptimizedCssForUrlController::class);
