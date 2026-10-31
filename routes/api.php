<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\CasilleroController;
use App\Http\Controllers\DispositivoController;
use App\Http\Controllers\RostroController;
use App\Http\Middleware\AutenticarDispositivo;
use Illuminate\Support\Facades\Route;


// Públicas
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-email', [AuthController::class, 'verify']);
Route::post('/resend-code', [AuthController::class, 'resendCode']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Protegidas (requieren token)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Registro facial (HU10): recibe la imagen y la envía al servicio Python.
    Route::post('/rostro', [RostroController::class, 'store']);

    // Métricas dinámicas del Dashboard
    Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);

    // CRUD de Usuarios
    Route::get('/usuarios', [UsuarioController::class, 'index']);
    Route::post('/usuarios', [UsuarioController::class, 'store']);
    Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);

    // Catálogos para el formulario de Usuarios
    Route::get('/roles', [UsuarioController::class, 'roles']);
    Route::get('/organizaciones', [UsuarioController::class, 'organizaciones']);

    // Casilleros (Etapa 1: monitoreo · Etapa 2: bloqueo · Etapa 3: asignación)
    Route::get('/casilleros', [CasilleroController::class, 'index']);
    Route::post('/casilleros/{id}/bloquear', [CasilleroController::class, 'bloquear']);
    Route::post('/casilleros/{id}/desbloquear', [CasilleroController::class, 'desbloquear']);
    Route::get('/casilleros/{id}/usuarios-asignables', [CasilleroController::class, 'usuariosAsignables']);
    Route::get('/casilleros/{id}/asignacion', [CasilleroController::class, 'asignacion']);
    Route::post('/casilleros/{id}/asignar', [CasilleroController::class, 'asignar']);
    Route::post('/casilleros/{id}/liberar', [CasilleroController::class, 'liberar']);
});

Route::prefix('dispositivo')
    ->middleware(AutenticarDispositivo::class)
    ->group(function () {
        Route::post('/acceso', [DispositivoController::class, 'acceso']);
        Route::post('/evento', [DispositivoController::class, 'evento']);
        Route::get('/tarjetas', [DispositivoController::class, 'tarjetas']);
    });

