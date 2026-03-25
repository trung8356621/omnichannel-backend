<?php

use App\Addons\WpHeadless\Http\Middleware\WpHeadlessReadTokenAuth;
use App\Addons\WpHeadless\Http\Controllers\Api\OptimizedCssByClassesController;
use App\Addons\WpHeadless\Http\Controllers\Api\OptimizedCssForUrlController;
use App\Addons\WpHeadless\Http\Controllers\Api\StylesOptimizedController;
use App\Addons\WpHeadless\Http\Controllers\Api\SubmitCommentController;
use App\Addons\WpHeadless\Http\Controllers\Api\TemplateReceiveController;
use App\Addons\WpHeadless\Http\Controllers\Api\TemplatesController;
use App\Addons\WpHeadless\Http\Controllers\Api\WpBridgeController;
use Illuminate\Support\Facades\Route;

Route::post('/wp-bridge/refresh-key', [WpBridgeController::class, 'refreshKey']);
Route::post('/wp-bridge/sync-site-data', [WpBridgeController::class, 'syncSiteData']);
Route::get('/wp-bridge/sync-site-data/status', [WpBridgeController::class, 'syncSiteDataStatus']);

/** Hook: WordPress đẩy template lẻ (header_block, footer_block) → lưu wp_headless_templates */
Route::post('/wp-headless/template-receive', TemplateReceiveController::class);

Route::post('/wp-headless/styles-optimized', [StylesOptimizedController::class, 'store']);
Route::post('/wp-headless/templates', TemplatesController::class)->middleware(WpHeadlessReadTokenAuth::class);

Route::post('/wp-headless/optimized-css-for-url', OptimizedCssForUrlController::class)->middleware(WpHeadlessReadTokenAuth::class);
Route::post('/wp-headless/optimized-css-by-classes', OptimizedCssByClassesController::class)->middleware(WpHeadlessReadTokenAuth::class);

/** Next.js: form đăng comment → Laravel forward tới WordPress REST API (wp/v2/comments). */
Route::post('/wp-headless/submit-comment', SubmitCommentController::class);
