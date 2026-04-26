<?php

use App\Http\AuthController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\WebhooksController;
use App\Http\Middleware\FoodicsWebhook;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::post('/language', [LanguageController::class, 'switch'])->name('language.switch');
Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::get('daftra/auth', [AuthController::class, 'daftraRedirect'])->name('daftra.auth');
Route::get('daftra/auth/callback', [AuthController::class, 'daftraCallback'])->name('daftra.callback');
Route::get('foodics/auth', [AuthController::class, 'foodicsRedirect'])->name('foodics.auth');
Route::get('foodics/auth/callback', [AuthController::class, 'foodicsCallback'])->name('foodics.callback');

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices');
    Route::post('/invoices/sync', [InvoiceController::class, 'sync'])->name('invoices.sync');
    Route::get('/invoices/sync-status', [InvoiceController::class, 'syncStatus'])->name('invoices.sync-status');
    Route::post('/invoices/{invoice}/retry-sync', [InvoiceController::class, 'retrySync'])->name('invoices.retry-sync');
    Route::get('/products', [ProductController::class, 'index'])->name('products');
    Route::post('/products/sync', [ProductController::class, 'sync'])->name('products.sync');
    Route::get('/products/sync-status', [ProductController::class, 'syncStatus'])->name('products.sync-status');
    Route::post('/products/{product}/resync', [ProductController::class, 'resync'])->name('products.resync');
    Route::get('/settings', [SettingController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::get('/contact', [ContactMessageController::class, 'index'])->name('contact');
    Route::post('/contact', [ContactMessageController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('contact.store');
});

Route::post('webhooks/foodics', WebhooksController::class)
    ->middleware(FoodicsWebhook::class)
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('webhooks');
