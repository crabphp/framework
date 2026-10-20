<?php

declare(strict_types=1);

use App\Http\Controllers\BenchController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', [BenchController::class, 'hello']);
Route::get('/users/{id}', [BenchController::class, 'user']);
Route::post('/users', [BenchController::class, 'validateUser']);
Route::get('/__bench', [BenchController::class, 'metadata']);
