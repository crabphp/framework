<?php

use App\Controller\BenchController;
use Hyperf\HttpServer\Router\Router;

Router::get('/hello', [BenchController::class, 'hello']);
Router::get('/users/{id}', [BenchController::class, 'user']);
Router::post('/users', [BenchController::class, 'create']);
Router::get('/__bench', [BenchController::class, 'metadata']);
