<?php

use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\MovementController;
use Illuminate\Support\Facades\Route;

Route::get('/clients', [ClientController::class, 'index']);
Route::post('/clients', [ClientController::class, 'store']);
Route::get('/clients/{client}', [ClientController::class, 'show']);
Route::get('/clients/{client}/balance', [ClientController::class, 'balance']);
Route::get('/clients/{client}/holdings', [ClientController::class, 'holdings']);
Route::get('/clients/{client}/movements', [ClientController::class, 'movements']);

Route::post('/clients/{client}/deposit', [MovementController::class, 'deposit']);
Route::post('/clients/{client}/withdraw', [MovementController::class, 'withdraw']);
Route::post('/clients/{client}/buy', [MovementController::class, 'buy']);
Route::post('/clients/{client}/sell', [MovementController::class, 'sell']);
