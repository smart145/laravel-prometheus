<?php

use Illuminate\Support\Facades\Route;
use Smart145\Prometheus\Http\Controllers\MetricsController;

Route::get(
    config('prometheus.route', 'prometheus'),
    MetricsController::class
)->middleware(config('prometheus.middleware', []))
    ->name('prometheus.metrics');

