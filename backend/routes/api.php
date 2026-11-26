<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommandController;
use App\Http\Controllers\Api\ReadingController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('registration.enabled'); // Permite desabilitar cadastro público por configuração de implantação.
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('matlab.token')->group(function () {
    Route::post('/readings', [ReadingController::class, 'store']);
    Route::get('/commands', [CommandController::class, 'pending']);
    Route::post('/commands/ack', [CommandController::class, 'acknowledge']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/readings/history', [ReadingController::class, 'history']);
    Route::post('/commands/add', [CommandController::class, 'store']);

    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/logs', [AdminController::class, 'logs']);
        Route::get('/backups', [AdminController::class, 'backups']); // Lista os backups disponíveis somente para administradores.
        Route::post('/backups', [AdminController::class, 'createBackup']); // Permite criar um backup manual auditado.
        Route::post('/restore', [AdminController::class, 'restore']);
    });
});
