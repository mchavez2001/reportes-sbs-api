<?php

use App\Http\Controllers\ReporteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/company', [ReporteController::class, 'getCompanys']);