<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('home');

Route::get('/checkout/{reservation}', [CheckoutController::class, 'show'])->name('checkout.show');

Route::get('/test', [TestController::class, 'index'])->name('test.index');
