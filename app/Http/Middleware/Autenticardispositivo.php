<?php

namespace App\Http\Middleware;

use App\Models\Dispositivo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al ESP32 con dos encabezados:
 *   X-Device-Id:   MAC del ESP32 (dispositivos.identificador)
 *   Authorization: Bearer <token>  (se compara su SHA-256 con dispositivos.token)
 *
 * Si es valido, deja el modelo disponible en:
 *   $request->attributes->get('dispositivo')
 */
class AutenticarDispositivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $identificador = strtoupper(trim((string) $request->header('X-Device-Id')));
        $token = (string) $request->bearerToken();

        if ($identificador === '' || $token === '') {
            return response()->json(['message' => 'Dispositivo no autenticado.'], 401);
        }

        $dispositivo = Dispositivo::where('identificador', $identificador)->first();

        if (! $dispositivo || ! $dispositivo->tokenEsValido($token)) {
            return response()->json(['message' => 'Dispositivo no autenticado.'], 401);
        }

        if (! $dispositivo->estaActivo()) {
            return response()->json(['message' => 'Dispositivo desactivado.'], 403);
        }

        $dispositivo->update(['ultima_conexion' => now()]);

        $request->attributes->set('dispositivo', $dispositivo);

        return $next($request);
    }
}