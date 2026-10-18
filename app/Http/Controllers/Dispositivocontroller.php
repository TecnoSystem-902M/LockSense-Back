<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Asignacion;
use App\Models\CredencialRfid;
use App\Models\Dispositivo;
use App\Models\EventoCasillero;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints que consume el ESP32 (HU6: registro de accesos y eventos).
 *
 *   POST /api/dispositivo/acceso    Valida una tarjeta y registra el intento.
 *   POST /api/dispositivo/evento    Registra apertura/cierre de puerta y alertas.
 *   GET  /api/dispositivo/tarjetas  Lista de tarjetas para el modo sin conexion.
 */
class DispositivoController extends Controller
{
    private const CREDENCIAL_ACTIVA = 'A';
    private const ASIGNACION_ACTIVA = 'A';

    private const EVENTOS_VALIDOS = [
        'puerta_abierta',
        'puerta_cerrada',
        'puerta_no_abierta',
        'apertura_no_autorizada',
        'bloqueo_activado',
        'bloqueo_finalizado',
        'intento_durante_bloqueo',
    ];

    private const DESCRIPCIONES = [
        'puerta_abierta'          => 'Puerta abierta después de un acceso autorizado.',
        'puerta_cerrada'          => 'Puerta cerrada.',
        'puerta_no_abierta'       => 'Acceso autorizado, pero la puerta no se abrió.',
        'apertura_no_autorizada'  => 'La puerta se abrió sin un acceso autorizado.',
        'bloqueo_activado'        => 'Lector bloqueado temporalmente por intentos fallidos.',
        'bloqueo_finalizado'      => 'Terminó el bloqueo temporal del lector.',
        'intento_durante_bloqueo' => 'Se presentó una tarjeta mientras el lector estaba bloqueado.',
    ];

    /**
     * POST /api/dispositivo/acceso
     * Body: { "uid": "D9 94 15 E6", "metodo": "rfid" }
     */
    public function acceso(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uid'    => ['required', 'string', 'max:50'],
            'metodo' => ['nullable', 'string', 'max:10'],
        ]);

        /** @var Dispositivo $dispositivo */
        $dispositivo = $request->attributes->get('dispositivo');
        $uid = $this->normalizarUid($data['uid']);
        $metodo = $data['metodo'] ?? 'rfid';

        $credencial = CredencialRfid::whereRaw(
            "REPLACE(UPPER(codigo), ' ', '') = ?",
            [str_replace(' ', '', $uid)]
        )->first();

        if (! $credencial) {
            return $this->denegar($dispositivo, $uid, $metodo, 'tarjeta_no_registrada');
        }

        if ($credencial->estado !== self::CREDENCIAL_ACTIVA) {
            return $this->denegar($dispositivo, $uid, $metodo, 'tarjeta_inactiva', $credencial->usuarios_id);
        }

        // Asignacion activa del dueno de la tarjeta, en un casillero de ESTE dispositivo.
        $asignacion = Asignacion::with('casillero')
            ->where('usuarios_id', $credencial->usuarios_id)
            ->where('estado', self::ASIGNACION_ACTIVA)
            ->whereHas('casillero', fn ($q) => $q->where('dispositivos_id', $dispositivo->id))
            ->latest('id')
            ->first();

        if (! $asignacion) {
            return $this->denegar($dispositivo, $uid, $metodo, 'sin_casillero_asignado', $credencial->usuarios_id);
        }

        $casillero = $asignacion->casillero;

        if ($casillero->estaBloqueado()) {
            return $this->denegar($dispositivo, $uid, $metodo, 'casillero_bloqueado',
                $credencial->usuarios_id, $casillero->id);
        }

        $acceso = Acceso::create([
            'metodo'          => $metodo,
            'resultado'       => Acceso::AUTORIZADO,
            'fecha_hora'      => now(),
            'casilleros_id'   => $casillero->id,
            'usuarios_id'     => $credencial->usuarios_id,
            'dispositivos_id' => $dispositivo->id,
            'codigo_leido'    => $uid,
        ]);

        return response()->json([
            'permitido' => true,
            'posicion'  => $casillero->posicion,
            'acceso_id' => $acceso->id,
        ]);
    }

    /**
     * POST /api/dispositivo/evento
     * Body: { "tipo": "puerta_cerrada", "posicion": 1, "acceso_id": 25, "duracion_segundos": 12 }
     */
    public function evento(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo'              => ['required', 'string', 'in:' . implode(',', self::EVENTOS_VALIDOS)],
            'posicion'          => ['nullable', 'integer', 'min:1', 'max:99'],
            'acceso_id'         => ['nullable', 'integer'],
            'duracion_segundos' => ['nullable', 'integer', 'min:0'],
            'uid'               => ['nullable', 'string', 'max:50'],
        ]);

        /** @var Dispositivo $dispositivo */
        $dispositivo = $request->attributes->get('dispositivo');
        $tipo = $data['tipo'];

        $casillero = isset($data['posicion'])
            ? $dispositivo->casilleros()->get()
                ->first(fn ($c) => $c->posicion === (int) $data['posicion'])
            : null;

        $descripcion = self::DESCRIPCIONES[$tipo];
        if (isset($data['duracion_segundos'])) {
            $descripcion .= " Duración: {$data['duracion_segundos']} s.";
        }
        if (! empty($data['uid'])) {
            $descripcion .= ' UID: ' . $this->normalizarUid($data['uid']) . '.';
        }

        $evento = EventoCasillero::create([
            'tipo_evento'     => strtoupper($tipo),
            'descripcion'     => mb_substr($descripcion, 0, 255),
            'fecha_hora'      => now(),
            'casilleros_id'   => $casillero?->id,
            'dispositivos_id' => $dispositivo->id,
        ]);

        // Completar el acceso con apertura, cierre y duracion.
        if (! empty($data['acceso_id'])) {
            $acceso = $dispositivo->accesos()->find($data['acceso_id']);

            if ($acceso && $tipo === 'puerta_abierta') {
                $acceso->update(['fecha_apertura' => now()]);
            } elseif ($acceso && $tipo === 'puerta_cerrada') {
                $acceso->update([
                    'fecha_cierre'      => now(),
                    'duracion_segundos' => $data['duracion_segundos'] ?? null,
                ]);
            }
        }

        return response()->json(['registrado' => true, 'evento_id' => $evento->id]);
    }

    /**
     * GET /api/dispositivo/tarjetas
     * Respuesta: { "tarjetas": [ { "uid": "D9 94 15 E6", "posicion": 1 } ] }
     */
    public function tarjetas(Request $request): JsonResponse
    {
        /** @var Dispositivo $dispositivo */
        $dispositivo = $request->attributes->get('dispositivo');

        // Asignaciones activas en casilleros de este dispositivo que no esten bloqueados.
        $asignaciones = Asignacion::with('casillero')
            ->where('estado', self::ASIGNACION_ACTIVA)
            ->whereHas('casillero', fn ($q) => $q
                ->where('dispositivos_id', $dispositivo->id)
                ->whereDoesntHave('bloqueos', fn ($b) => $b->where('activo', 'A')))
            ->get()
            ->keyBy('usuarios_id');

        $tarjetas = CredencialRfid::where('estado', self::CREDENCIAL_ACTIVA)
            ->whereIn('usuarios_id', $asignaciones->keys())
            ->get()
            ->map(fn ($credencial) => [
                'uid'      => $this->normalizarUid($credencial->codigo),
                'posicion' => $asignaciones[$credencial->usuarios_id]->casillero->posicion,
            ])
            ->values();

        return response()->json(['tarjetas' => $tarjetas]);
    }

    // ==================================================
    // Auxiliares
    // ==================================================

    private function denegar(Dispositivo $dispositivo, string $uid, string $metodo, string $motivo,
                             ?int $usuarioId = null, ?int $casilleroId = null): JsonResponse
    {
        $acceso = Acceso::create([
            'metodo'          => $metodo,
            'resultado'       => Acceso::DENEGADO,
            'motivo'          => $motivo,
            'fecha_hora'      => now(),
            'casilleros_id'   => $casilleroId,
            'usuarios_id'     => $usuarioId,
            'dispositivos_id' => $dispositivo->id,
            'codigo_leido'    => $uid,
        ]);

        return response()->json([
            'permitido' => false,
            'motivo'    => $motivo,
            'acceso_id' => $acceso->id,
        ]);
    }

    /** "d99415e6" o "D9 94 15 E6" -> "D9 94 15 E6" (formato del ESP32) */
    private function normalizarUid(string $uid): string
    {
        $limpio = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $uid));

        return implode(' ', str_split($limpio, 2));
    }
}