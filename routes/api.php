<?php

use App\Http\Controllers\ReservationController;
use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/products',               [ReservationController::class, 'products']);
Route::get('/reservations/active',     [ReservationController::class, 'activeReservations']);
Route::post('/reserve/{variantId}',    [ReservationController::class, 'reserve']);
Route::post('/confirm/{id}',           [ReservationController::class, 'confirm']);
Route::post('/cancel/{id}',            [ReservationController::class, 'cancel']);

Route::get('/test/variants',           [TestController::class, 'variants']);
Route::post('/test/reset',             [TestController::class, 'reset']);
