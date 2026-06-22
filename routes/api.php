<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\ExternalPluginUpdateController;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/check-npm', [ApiController::class, 'checkNpm']);

Route::prefix('plugins/{slug}')->group(function (): void {
    Route::get('/update-check', [ExternalPluginUpdateController::class, 'checkUpdate'])
        ->name('api.external-plugin.update-check');
    Route::get('/info.json', [ExternalPluginUpdateController::class, 'infoJson'])
        ->name('api.external-plugin.info');
    Route::get('/download/{version}', [ExternalPluginUpdateController::class, 'download'])
        ->name('api.external-plugin.download');
});

Route::get('/seo/plugin/update-check', [ExternalPluginUpdateController::class, 'legacyCheckUpdate'])
    ->name('api.seo.plugin.update-check');
Route::get('/seo/plugin/info.json', fn () => app(ExternalPluginUpdateController::class)->infoJson('omi-seo-ai-bridge'))
    ->name('api.seo.plugin.info');
Route::get('/seo/plugin/download/{version}', [ExternalPluginUpdateController::class, 'legacyDownload'])
    ->name('api.seo.plugin.download');