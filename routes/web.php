<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => redirect('/dashboard'));
Route::get('/dashboard', DashboardController::class)->name('dashboard');
