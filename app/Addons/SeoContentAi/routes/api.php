<?php

declare(strict_types=1);

use App\Addons\SeoContentAi\Http\Controllers\Api\SeoWpBridgeController;
use Illuminate\Support\Facades\Route;

Route::get('/seo-wp-bridge/ping', [SeoWpBridgeController::class, 'ping']);
Route::post('/seo-wp-bridge/push-content', [SeoWpBridgeController::class, 'pushContent']);
