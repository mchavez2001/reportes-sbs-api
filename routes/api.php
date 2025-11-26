<?php

use App\Http\Controllers\ReporteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/insurance/resumen', [App\Http\Controllers\ReporteController::class, 'resumen']);
