<?php

use App\Http\AuthController;
use App\Http\Controllers\WebhooksController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;


Auth::loginUsingId(1);

Route::get('/', function () {
    return view('welcome');
})->name('home');


Route::get('daftra/auth/callback', [AuthController::class, 'daftraCallback'])->name('daftra.callback');
Route::get('foodics/auth/callback', [AuthController::class, 'foodicsCallback'])->name('foodics.callback');
Route::post('webhooks/foodics', WebhooksController::class)
    ->middleware(\App\Http\Middleware\FoodicsWebhook::class)
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('webhooks');
