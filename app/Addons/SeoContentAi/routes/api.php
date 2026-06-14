<?php

declare(strict_types=1);

use App\Addons\SeoContentAi\Http\Controllers\Api\SeoWpBridgeController;
use App\Addons\SeoContentAi\Http\Controllers\PluginUpdateController;
use Illuminate\Support\Facades\Route;

Route::get('/seo-wp-bridge/ping', [SeoWpBridgeController::class, 'ping']);
Route::post('/seo-wp-bridge/push-content', [SeoWpBridgeController::class, 'pushContent']);

Route::get('/seo/plugin/update-check', [PluginUpdateController::class, 'checkUpdate'])
    ->name('api.seo.plugin.update-check');
Route::get('/seo/plugin/info.json', [PluginUpdateController::class, 'infoJson'])
    ->name('api.seo.plugin.info');
Route::get('/seo/plugin/download/{version}', [PluginUpdateController::class, 'download'])
    ->name('api.seo.plugin.download');
