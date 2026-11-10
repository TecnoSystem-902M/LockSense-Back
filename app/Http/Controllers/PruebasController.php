<?php

namespace App\Http\Controllers;

use App\Models\Anomalia;
use App\Models\Casillero;
use App\Services\RegistroAnomalias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SOLO PARA PRUEBAS / DEMOSTRACION.
 *
 * Simula el sensor de vibracion (SW-420) con un boton en la pagina web.
 * No pide iniciar sesion: se protege con una clave secreta que va en el
 * enlace de la pagina (PRUEBAS_CLAVE) y se puede apagar con
 * PRUEBAS_HABILITADAS=false.
 *
 * Usa EXACTAMENTE el mismo codigo que el sensor real
 * (RegistroAnomalias->vibracion): registra la anomalia, pide la foto a la
 * ESP32-CAM, YOLO la analiza y se manda la notificacion al dueno.
 *
 * POST /api/pruebas/simular-vibracion   { "clave": "..." }
 */
class PruebasController extends Controller
{
    // Igual que el sensor real: no se puede volver a simular antes de esto.
    private const ESPERA_SEGUNDOS = 10;

    public function simularVibracion(Request $request, RegistroAnomalias $anomalias): JsonResponse
    {
        $config = config('services.pruebas');

        // Apagado o sin clave configurada: la ruta "no existe".
        if (! $config['habilitadas'] || empty($config['clave'])) {
            return response()->json(['message' => 'No disponible.'], 404);
        }

        $clave = (string) $request->input('clave');

        if (! hash_equals((string) $config['clave'], $clave)) {
            return response()->json(['message' => 'Enlace no válido.'], 404);
        }

        $casillero = Casillero::with('dispositivo')->find($config['casillero_id']);

        if (! $casillero) {
            return response()->json([
                'message' => 'El casillero de prueba no existe. Revisa PRUEBAS_CASILLERO_ID.',
            ], 422);
        }

        if (! $casillero->dispositivo) {
            return response()->json([
                'message' => "El casillero {$casillero->numero} no está conectado a ningún ESP32.",
            ], 422);
        }

        // Espera entre simulaciones (como el sensor real).
        $reciente = Anomalia::query()
            ->where('casilleros_id', $casillero->id)
            ->where('tipo', Anomalia::TIPO_VIBRACION)
            ->where('fecha_hora', '>=', now()->subSeconds(self::ESPERA_SEGUNDOS))
            ->exists();

        if ($reciente) {
            return response()->json([
                'message' => 'Espera unos segundos antes de volver a simular.',
            ], 429);
        }

        $anomalia = $anomalias->vibracion($casillero->dispositivo, $casillero);

        return response()->json([
            'ok'          => true,
            'message'     => 'Golpe simulado. La cámara tomará la foto en unos segundos.',
            'anomalia_id' => $anomalia->id,
            'casillero'   => $casillero->numero,
        ]);
    }
}