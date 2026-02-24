<?php

use App\Addons\WpHeadless\Http\Controllers\Api\GetPostCommentsController;
use App\Addons\WpHeadless\Http\Controllers\Api\OptimizedCssForUrlController;
use App\Addons\WpHeadless\Http\Controllers\Api\StylesOptimizedController;
use App\Addons\WpHeadless\Http\Controllers\Api\SubmitCommentController;
use App\Addons\WpHeadless\Http\Controllers\Api\TemplateReceiveController;
use App\Addons\WpHeadless\Http\Controllers\Api\TemplatesController;
use App\Addons\WpHeadless\Http\Controllers\Api\WpBridgeController;
use Illuminate\Support\Facades\Route;

Route::post('/wp-bridge/refresh-key', [WpBridgeController::class, 'refreshKey']);
Route::post('/wp-bridge/sync-site-data', [WpBridgeController::class, 'syncSiteData']);

/** Hook: WordPress đẩy template lẻ (header_block, footer_block) → lưu wp_headless_templates */
Route::post('/wp-headless/template-receive', TemplateReceiveController::class);

Route::post('/wp-headless/styles-optimized', [StylesOptimizedController::class, 'store']);
Route::get('/wp-headless/templates', TemplatesController::class);

/** Next.js: gửi url → Laravel lấy data WordPress (GraphQL) + CSS tối ưu, trả về data + optimizedCssUrls. */
Route::post('/wp-headless/page-by-url', OptimizedCssForUrlController::class);
Route::post('/wp-headless/optimized-css-for-url', OptimizedCssForUrlController::class);

/** Next.js: lấy danh sách comment của post (request riêng, WordPress REST API). */
Route::get('/wp-headless/post-comments', GetPostCommentsController::class);

/** Next.js: form đăng comment → Laravel forward tới WordPress REST API (wp/v2/comments). */
Route::post('/wp-headless/submit-comment', SubmitCommentController::class);

/** Next.js: widget sidebar (products, categories, filters, posts) — Laravel proxy sang WordPress REST tvh/v1/widget-*. */
Route::get('/wp-headless/widget/products', [\App\Addons\WpHeadless\Http\Controllers\Api\WpHeadlessWidgetController::class, 'products']);
Route::get('/wp-headless/widget/product-categories', [\App\Addons\WpHeadless\Http\Controllers\Api\WpHeadlessWidgetController::class, 'productCategories']);
Route::get('/wp-headless/widget/layered-nav', [\App\Addons\WpHeadless\Http\Controllers\Api\WpHeadlessWidgetController::class, 'layeredNav']);
Route::get('/wp-headless/widget/price-filter', [\App\Addons\WpHeadless\Http\Controllers\Api\WpHeadlessWidgetController::class, 'priceFilter']);
Route::get('/wp-headless/widget/posts', [\App\Addons\WpHeadless\Http\Controllers\Api\WpHeadlessWidgetController::class, 'posts']);
