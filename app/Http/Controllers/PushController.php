<?php

namespace App\Http\Controllers;

use App\Models\PushSuscripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificaciones push de anomalías (solo rol USUARIO, el dueño del casillero).
 *
 * GET    /api/push/clave-publica  -> clave pública VAPID que necesita el navegador
 * POST   /api/push/suscripcion    -> guarda el dispositivo que aceptó las alertas
 * DELETE /api/push/suscripcion    -> quita ese dispositivo
 */
class PushController extends Controller
{
    private const ROL_USUARIO = 3;

    public function clavePublica(): JsonResponse
    {
        return response()->json([
            'clave_publica' => config('services.webpush.public_key'),
        ]);
    }

    public function suscribir(Request $request): JsonResponse
    {
        $usuario = $request->user();

        abort_unless((int) $usuario->roles_id === self::ROL_USUARIO, 403,
            'Solo los usuarios reciben alertas de su casillero.');

        // Es lo que da el navegador con subscription.toJSON()
        $datos = $request->validate([
            'endpoint'    => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth'   => ['required', 'string', 'max:255'],
        ]);

        // Si el dispositivo ya estaba guardado (aunque fuera de otro usuario
        // que usó la misma computadora), ahora queda a nombre de este usuario.
        PushSuscripcion::updateOrCreate(
            ['endpoint' => $datos['endpoint']],
            [
                'usuarios_id' => $usuario->id,
                'p256dh'      => $datos['keys']['p256dh'],
                'auth'        => $datos['keys']['auth'],
            ]
        );

        return response()->json(['ok' => true], 201);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
        ]);

        PushSuscripcion::query()
            ->where('endpoint', $datos['endpoint'])
            ->where('usuarios_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}