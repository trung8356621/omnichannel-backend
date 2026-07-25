<?php

use App\Http\Controllers\Api\ExternalPluginUpdateController;
use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

// Routes mặc định (Breeze)
Route::get('/', function () {
    return '123';
});

require __DIR__.'/auth.php';

// Google Auth Routes
Route::get('auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login');
Route::get('auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);

Route::get('wp-plugin-release', static function () {
    $query = request()->getQueryString();

    return redirect('/admin/wp-plugin-release'.($query ? '?'.$query : ''));
})->name('wp-plugin-release.redirect');

Route::get('storage/plugins/{package_prefix}/info.json', [ExternalPluginUpdateController::class, 'infoJsonByPackagePrefix'])
    ->where('package_prefix', '[a-z0-9\-]+')
    ->name('external-plugin.info-json-file');

Route::get('wp-plugin-release/download/{slug}/{version}', [ExternalPluginUpdateController::class, 'downloadForPanel'])
    ->name('external-plugin.download');

Route::get('seo/wp-plugin/download/{version}', [ExternalPluginUpdateController::class, 'legacyDownloadForPanel'])
    ->name('seo.wp-plugin.download');
