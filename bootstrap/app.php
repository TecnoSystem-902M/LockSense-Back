<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php', 
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tratar SIEMPRE las peticiones a /api/* como "espera JSON" al renderizar
        // excepciones. Así Laravel devuelve la respuesta JSON de la excepción
        // (p. ej. 401 en AuthenticationException) en lugar de intentar redirigir
        // a la ruta web "login" (inexistente), que provocaba el error 500.
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Mensaje JSON uniforme para peticiones de API no autenticadas.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });

        // Para peticiones de API sin encabezado Accept, el manejador por defecto de
        // AuthenticationException intenta redirigir a la ruta web "login" (que no
        // existe) y lanza RouteNotFoundException -> 500. Lo interceptamos y
        // devolvemos 401 JSON, preservando el comportamiento web normal.
        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            if (($request->is('api/*') || $request->expectsJson())
                && str_contains($e->getMessage(), 'Route [login] not defined')) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });
    })->create();
