<?php

use App\Addons\WpHeadless\Filament\Pages\WpHeadlessDashboard;
use Illuminate\Support\Facades\Route;

Route::get('/wp-headless/manage', WpHeadlessDashboard::class)->name(name: 'wp-headless.manage');
