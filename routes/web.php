<?php

use App\Http\AuthController;
use App\Http\Controllers\WebhooksController;
use App\Http\Middleware\FoodicsWebhook;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::get('daftra/auth', [AuthController::class, 'daftraRedirect'])->name('daftra.auth');
Route::get('daftra/auth/callback', [AuthController::class, 'daftraCallback'])->name('daftra.callback');
Route::get('foodics/auth', [AuthController::class, 'foodicsRedirect'])->name('foodics.auth');
Route::get('foodics/auth/callback', [AuthController::class, 'foodicsCallback'])->name('foodics.callback');

Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view('welcome');
    })->name('home');
});
Route::post('webhooks/foodics', WebhooksController::class)
    ->middleware(FoodicsWebhook::class)
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('webhooks');
