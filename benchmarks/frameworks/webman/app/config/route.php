<?php

use app\controller\BenchController;
use Webman\Route;

Route::get('/hello', [BenchController::class, 'hello']);
Route::get('/users/{id}', [BenchController::class, 'user']);
Route::post('/users', [BenchController::class, 'create']);
Route::get('/__bench', [BenchController::class, 'metadata']);
Route::disableDefaultRoute();
