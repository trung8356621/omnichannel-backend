<?php

use App\Addons\SeoContentAi\Filament\Pages\SeoDashboard;
use Illuminate\Support\Facades\Route;

Route::get('/seo/dashboard', SeoDashboard::class)->name('seo.dashboard');