<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Asignacion;
use App\Models\Bloqueo;
use App\Models\CredencialRfid;
use App\Models\Dispositivo;
use App\Models\EventoCasillero;
use App\Services\NotificadorSeguridad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Endpoints que consume el ESP32.
 *
 *   POST /api/dispositivo/acceso    Valida una tarjeta y registra el intento (HU6).
 *                                   Cuenta intentos fallidos y bloquea el lector (HU5).
 *   POST /api/dispositivo/evento    Registra apertura/cierre de puerta y alertas (HU6, HU5).
 *   GET  /api/dispositivo/tarjetas  Lista de tarjetas para el modo sin conexion.
 */
class DispositivoController extends Controller
{
    private const CREDENCIAL_ACTIVA = 'A';
    private const ASIGNACION_ACTIVA = 'A';

    // ---------- HU5: bloqueo del lector por intentos fallidos ----------
    private const MAX_INTENTOS_FALLIDOS = 5;    // intentos para bloquear
    private const VENTANA_SEGUNDOS      = 120;  // ...dentro de este tiempo
    private const DURACION_BLOQUEO      = 30;   // segundos que dura el bloqueo
    private const TIPO_BLOQUEO_LECTOR   = 'INTENTOS_FALLIDOS';

    // Solo estos motivos cuentan como intento sospechoso.
    // (Un casillero bloqueado por el administrador no cuenta.)
    private const MOTIVOS_QUE_CUENTAN = [
        'tarjeta_no_registrada',
        'tarjeta_inactiva',
        'sin_casillero_asignado',
    ];

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

    public function __construct(private NotificadorSeguridad $notificador)
    {
    }

    /**
     * POST /api/dispositivo/acceso
     * Body: { "uid": "D9 94 15 E6", "metodo": "rfid" }
     *
     * Respuestas:
     *   { "permitido": true,  "posicion": 1, "acceso_id": 25 }
     *   { "permitido": false, "motivo": "...", "acceso_id": 26 }
     *   { "permitido": false, "motivo": "...", "acceso_id": 27, "bloqueado": true, "segundos_restantes": 30 }
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

        // HU5: si el lector esta bloqueado, no se valida la tarjeta.
        if ($bloqueo = $this->bloqueoLectorActivo($dispositivo)) {
            $acceso = $this->registrarDenegado($dispositivo, $uid, $metodo, 'lector_bloqueado');

            return $this->respuestaDenegado($acceso, $bloqueo);
        }

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

        $evento = $this->registrarEvento($dispositivo, $tipo, $descripcion, $casillero?->id);

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

        // HU5: la puerta se abrio sin autorizacion -> notificar.
        if ($tipo === 'apertura_no_autorizada') {
            $this->notificador->aperturaNoAutorizada($dispositivo, $casillero);
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
    // HU5: bloqueo del lector
    // ==================================================

    /**
     * Bloqueo del lector vigente, o null. Si ya vencio, lo desactiva.
     */
    private function bloqueoLectorActivo(Dispositivo $dispositivo): ?Bloqueo
    {
        $bloqueo = Bloqueo::where('dispositivos_id', $dispositivo->id)
            ->whereNull('casilleros_id')
            ->where('tipo', self::TIPO_BLOQUEO_LECTOR)
            ->where('activo', 'A')
            ->latest('id')
            ->first();

        if (! $bloqueo) {
            return null;
        }

        if (Carbon::parse($bloqueo->fin)->isPast()) {
            $bloqueo->update(['activo' => 'I']);
            $this->registrarEvento($dispositivo, 'bloqueo_finalizado', self::DESCRIPCIONES['bloqueo_finalizado']);

            return null;
        }

        return $bloqueo;
    }

    /**
     * Cuenta los intentos fallidos recientes y, si se alcanza el limite,
     * crea el bloqueo del lector y notifica a los administradores.
     */
    private function evaluarBloqueo(Dispositivo $dispositivo): ?Bloqueo
    {
        $desde = now()->subSeconds(self::VENTANA_SEGUNDOS);

        // No volver a contar intentos de antes del ultimo bloqueo.
        $finUltimoBloqueo = Bloqueo::where('dispositivos_id', $dispositivo->id)
            ->where('tipo', self::TIPO_BLOQUEO_LECTOR)
            ->max('fin');

        if ($finUltimoBloqueo && Carbon::parse($finUltimoBloqueo)->greaterThan($desde)) {
            $desde = Carbon::parse($finUltimoBloqueo);
        }

        $fallidos = $dispositivo->accesos()
            ->where('resultado', Acceso::DENEGADO)
            ->whereIn('motivo', self::MOTIVOS_QUE_CUENTAN)
            ->where('fecha_hora', '>', $desde)
            ->count();

        if ($fallidos < self::MAX_INTENTOS_FALLIDOS) {
            return null;
        }

        $bloqueo = Bloqueo::create([
            'tipo'            => self::TIPO_BLOQUEO_LECTOR,
            'motivo'          => "{$fallidos} intentos de acceso fallidos en menos de "
                               . self::VENTANA_SEGUNDOS . ' segundos.',
            'inicio'          => now(),
            'fin'             => now()->addSeconds(self::DURACION_BLOQUEO),
            'activo'          => 'A',
            'casilleros_id'   => null,
            'dispositivos_id' => $dispositivo->id,
        ]);

        $this->registrarEvento($dispositivo, 'bloqueo_activado',
            self::DESCRIPCIONES['bloqueo_activado'] . " Intentos: {$fallidos}.");

        $this->notificador->intentosFallidos($dispositivo, $fallidos, self::DURACION_BLOQUEO);

        return $bloqueo;
    }

    // ==================================================
    // Auxiliares
    // ==================================================

    private function denegar(Dispositivo $dispositivo, string $uid, string $metodo, string $motivo,
                             ?int $usuarioId = null, ?int $casilleroId = null): JsonResponse
    {
        $acceso = $this->registrarDenegado($dispositivo, $uid, $metodo, $motivo, $usuarioId, $casilleroId);

        $bloqueo = in_array($motivo, self::MOTIVOS_QUE_CUENTAN, true)
            ? $this->evaluarBloqueo($dispositivo)
            : null;

        return $this->respuestaDenegado($acceso, $bloqueo);
    }

    private function registrarDenegado(Dispositivo $dispositivo, string $uid, string $metodo, string $motivo,
                                       ?int $usuarioId = null, ?int $casilleroId = null): Acceso
    {
        return Acceso::create([
            'metodo'          => $metodo,
            'resultado'       => Acceso::DENEGADO,
            'motivo'          => $motivo,
            'fecha_hora'      => now(),
            'casilleros_id'   => $casilleroId,
            'usuarios_id'     => $usuarioId,
            'dispositivos_id' => $dispositivo->id,
            'codigo_leido'    => $uid,
        ]);
    }

    private function respuestaDenegado(Acceso $acceso, ?Bloqueo $bloqueo): JsonResponse
    {
        $respuesta = [
            'permitido' => false,
            'motivo'    => $acceso->motivo,
            'acceso_id' => $acceso->id,
        ];

        if ($bloqueo) {
            $respuesta['bloqueado'] = true;
            $respuesta['segundos_restantes'] = max(1, (int) ceil(
                now()->diffInSeconds(Carbon::parse($bloqueo->fin), false)
            ));
        }

        return response()->json($respuesta);
    }

    private function registrarEvento(Dispositivo $dispositivo, string $tipo, string $descripcion,
                                     ?int $casilleroId = null): EventoCasillero
    {
        return EventoCasillero::create([
            'tipo_evento'     => strtoupper($tipo),
            'descripcion'     => mb_substr($descripcion, 0, 255),
            'fecha_hora'      => now(),
            'casilleros_id'   => $casilleroId,
            'dispositivos_id' => $dispositivo->id,
        ]);
    }

    /** "d99415e6" o "D9 94 15 E6" -> "D9 94 15 E6" (formato del ESP32) */
    private function normalizarUid(string $uid): string
    {
        $limpio = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $uid));

        return implode(' ', str_split($limpio, 2));
    }
}