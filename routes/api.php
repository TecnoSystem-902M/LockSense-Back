<?php

use App\Http\Controllers\AnomaliaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\CasilleroController;
use App\Http\Controllers\DispositivoController;
use App\Http\Controllers\RostroController;
use App\Http\Middleware\AutenticarDispositivo;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-email', [AuthController::class, 'verify']);
Route::post('/resend-code', [AuthController::class, 'resendCode']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::post('/rostro', [RostroController::class, 'store']);

    Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);

    Route::get('/usuarios', [UsuarioController::class, 'index']);
    Route::post('/usuarios', [UsuarioController::class, 'store']);
    Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);

    Route::get('/roles', [UsuarioController::class, 'roles']);
    Route::get('/organizaciones', [UsuarioController::class, 'organizaciones']);

    Route::get('/casilleros', [CasilleroController::class, 'index']);
    Route::post('/casilleros/{id}/bloquear', [CasilleroController::class, 'bloquear']);
    Route::post('/casilleros/{id}/desbloquear', [CasilleroController::class, 'desbloquear']);
    Route::get('/casilleros/{id}/usuarios-asignables', [CasilleroController::class, 'usuariosAsignables']);
    Route::get('/casilleros/{id}/asignacion', [CasilleroController::class, 'asignacion']);
    Route::post('/casilleros/{id}/asignar', [CasilleroController::class, 'asignar']);
    Route::post('/casilleros/{id}/liberar', [CasilleroController::class, 'liberar']);

    Route::get('/anomalias', [AnomaliaController::class, 'index']);
    Route::get('/anomalias/resumen', [AnomaliaController::class, 'resumen']);
    Route::patch('/anomalias/{id}/estado', [AnomaliaController::class, 'actualizarEstado']);

    Route::get('/mis-anomalias', [AnomaliaController::class, 'misAnomalias']);
    Route::get('/mis-anomalias/{id}/evidencia', [AnomaliaController::class, 'miEvidencia']);
});

Route::prefix('dispositivo')
    ->middleware(AutenticarDispositivo::class)
    ->group(function () {
        Route::post('/acceso', [DispositivoController::class, 'acceso']);
        Route::post('/evento', [DispositivoController::class, 'evento']);
        Route::get('/tarjetas', [DispositivoController::class, 'tarjetas']);
        Route::post('/rostro', [DispositivoController::class, 'rostro']);
        Route::get('/ordenes', [DispositivoController::class, 'ordenes']);
        Route::post('/evidencia', [DispositivoController::class, 'evidencia']);
    });