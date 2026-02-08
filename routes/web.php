<?php

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\DashboardController as AdminDashboard;

// Routes mặc định (Breeze)
Route::get('/', function () {
    return '123';
});

// Google Auth Routes
Route::get('auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login');
Route::get('auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);

// User Dashboard (Chung cho Owner/Staff)
// Route::middleware(['auth', 'verified'])->group(function () {
//     Route::get('/dashboard', function () {
//         return view('dashboard');
//     })->name('dashboard');
// })->prefix('admin');

// Admin Area (Chỉ dành cho Role Admin)
// Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
//     Route::get('/dashboard', [AdminDashboard::class, 'index'])->name('dashboard');
//     Route::get('/users', [AdminDashboard::class, 'users'])->name('users');
//     Route::get('/services', [AdminDashboard::class, 'services'])->name('services');
// });
