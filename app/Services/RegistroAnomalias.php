<?php

namespace App\Services;

use App\Models\Acceso;
use App\Models\Anomalia;
use App\Models\Casillero;
use App\Models\Dispositivo;
use App\Models\Evidencia;
use App\Models\OrdenDispositivo;

/**
 * HU7: registra anomalias en la tabla `anomalias` y genera la alerta
 * (notificacion SOLO al dueno del casillero).
 *
 * Toda deteccion (sensores, YOLO, Isolation Forest) pasa por aqui, para que
 * todas queden guardadas igual y generen su notificacion.
 *
 * Etapa 3: las anomalias detectadas por sensores (origen REGLA) piden una
 * foto de evidencia a la ESP32-CAM de la organizacion (orden TOMAR_FOTO).
 */
class RegistroAnomalias
{
    // Segundos que la camara tiene para recoger la orden de foto.
    private const VIGENCIA_ORDEN_FOTO = 20;

    // HU7 (YOLO): a partir de cuantas personas se considera anomalia.
    private const PERSONAS_SOSPECHOSAS = 2;

    public function __construct(
        private NotificadorSeguridad $notificador,
        private ClienteFacial $clienteFacial,
    ) {
    }

    // ==================================================
    // Anomalias por reglas (sensores)
    // ==================================================

    public function aperturaNoAutorizada(Dispositivo $dispositivo, ?Casillero $casillero): Anomalia
    {
        $nombre = $casillero ? "El casillero {$casillero->numero}" : 'Un casillero';

        $anomalia = $this->crear([
            'tipo'            => Anomalia::TIPO_APERTURA_NO_AUTORIZADA,
            'descripcion'     => "{$nombre} se abrió sin un acceso autorizado.",
            'nivel_riesgo'    => Anomalia::RIESGO_ALTO,
            'confianza'       => 1,
            'origen'          => Anomalia::ORIGEN_REGLA,
            'organizaciones_id' => $dispositivo->organizaciones_id,
            'dispositivos_id' => $dispositivo->id,
            'casilleros_id'   => $casillero?->id,
        ]);

        $this->notificador->anomalia($anomalia);

        return $anomalia;
    }

    public function intentosFallidos(Dispositivo $dispositivo, int $intentos, int $segundosBloqueo): Anomalia
    {
        $anomalia = $this->crear([
            'tipo'            => Anomalia::TIPO_INTENTOS_FALLIDOS,
            'descripcion'     => "{$intentos} intentos de acceso fallidos seguidos. "
                               . "Lector bloqueado {$segundosBloqueo} segundos.",
            'nivel_riesgo'    => Anomalia::RIESGO_MEDIO,
            'confianza'       => 1,
            'origen'          => Anomalia::ORIGEN_REGLA,
            'organizaciones_id' => $dispositivo->organizaciones_id,
            'dispositivos_id' => $dispositivo->id,
            'casilleros_id'   => null,
        ]);

        // Sin casillero ni dueno: queda registrada, pero no se notifica a nadie.
        $this->notificador->anomalia($anomalia);

        return $anomalia;
    }

    /** Golpe o vibracion fuerte (sensor SW-420): posible intento de forzar. */
    public function vibracion(Dispositivo $dispositivo, ?Casillero $casillero): Anomalia
    {
        $nombre = $casillero ? "el casillero {$casillero->numero}" : 'un casillero';

        return $this->registrar([
            'tipo'              => Anomalia::TIPO_VIBRACION,
            'descripcion'       => "Se detectó un golpe o vibración fuerte en {$nombre}. "
                                 . 'Posible intento de forzarlo.',
            'nivel_riesgo'      => Anomalia::RIESGO_ALTO,
            'confianza'         => 1,
            'origen'            => Anomalia::ORIGEN_REGLA,
            'organizaciones_id' => $dispositivo->organizaciones_id,
            'dispositivos_id'   => $dispositivo->id,
            'casilleros_id'     => $casillero?->id,
        ]);
    }

    /** La puerta se quedo abierta mas tiempo del permitido. */
    public function puertaAbiertaProlongada(Dispositivo $dispositivo, ?Casillero $casillero,
                                            ?int $segundos, ?int $accesoId): Anomalia
    {
        $nombre = $casillero ? "El casillero {$casillero->numero}" : 'Un casillero';
        $tiempo = $segundos ? " ({$segundos} s)" : '';

        // Solo se liga el acceso si existe (evita error de llave foranea).
        if ($accesoId && ! Acceso::whereKey($accesoId)->exists()) {
            $accesoId = null;
        }

        return $this->registrar([
            'tipo'              => Anomalia::TIPO_PUERTA_ABIERTA_PROLONGADA,
            'descripcion'       => "{$nombre} lleva demasiado tiempo abierto{$tiempo}.",
            'nivel_riesgo'      => Anomalia::RIESGO_MEDIO,
            'confianza'         => 1,
            'origen'            => Anomalia::ORIGEN_REGLA,
            'organizaciones_id' => $dispositivo->organizaciones_id,
            'dispositivos_id'   => $dispositivo->id,
            'casilleros_id'     => $casillero?->id,
            'accesos_id'        => $accesoId,
        ]);
    }

    // ==================================================
    // HU7: analisis de la foto de evidencia con YOLO
    // ==================================================

    /**
     * Analiza la foto de evidencia de una anomalia con YOLO:
     *   - Agrega a la descripcion lo que se vio (personas, objetos).
     *   - Si hay 2 o mas personas  -> anomalia PERSONAS_MULTIPLES.
     *   - Si hay objetos sospechosos -> anomalia OBJETO_SOSPECHOSO.
     * Las nuevas anomalias llevan la misma foto y van al mismo dueno.
     *
     * Si el servicio de Python no responde, no pasa nada: la anomalia
     * original y su foto ya quedaron guardadas.
     */
    public function analizarEvidencia(Anomalia $anomalia, Evidencia $evidencia, ?Dispositivo $camara = null): void
    {
        $resultado = $this->clienteFacial->detectarObjetos(base64_decode($evidencia->imagen_base64));

        if (! $resultado['ok']) {
            return;
        }

        $personas    = $resultado['personas'];
        $sospechosos = $resultado['sospechosos'];

        // 1) Completar la descripcion de la anomalia original.
        $vio = $personas === 0
            ? 'no se detectó a ninguna persona (posible falsa alarma)'
            : ($personas === 1 ? 'se detectó 1 persona' : "se detectaron {$personas} personas");

        if ($sospechosos) {
            $vio .= ' y ' . $this->listarObjetos($sospechosos);
        }

        $anomalia->update([
            'descripcion' => rtrim($anomalia->descripcion, '.') . ". Cámara: {$vio}.",
        ]);

        $comunes = [
            'origen'            => Anomalia::ORIGEN_IA_IMAGEN,
            'organizaciones_id' => $anomalia->organizaciones_id,
            'dispositivos_id'   => $camara?->id ?? $anomalia->dispositivos_id,   // la camara que tomo la foto
            'casilleros_id'     => $anomalia->casilleros_id,
            'usuarios_id'       => $anomalia->usuarios_id,
            'accesos_id'        => $anomalia->accesos_id,
        ];

        $casillero = $anomalia->casillero ? "el casillero {$anomalia->casillero->numero}" : 'un casillero';

        // 2) Varias personas frente al casillero.
        if ($personas >= self::PERSONAS_SOSPECHOSAS) {
            $nueva = $this->registrar($comunes + [
                'tipo'         => Anomalia::TIPO_PERSONAS_MULTIPLES,
                'descripcion'  => "La cámara detectó {$personas} personas frente a {$casillero} "
                                . 'durante un evento sospechoso.',
                'nivel_riesgo' => Anomalia::RIESGO_ALTO,
                'confianza'    => round($resultado['confianza_personas'], 4),
            ]);
            $this->copiarEvidencia($evidencia, $nueva);
        }

        // 3) Objetos sospechosos (cuchillo, tijeras, bate).
        if ($sospechosos) {
            $nueva = $this->registrar($comunes + [
                'tipo'         => Anomalia::TIPO_OBJETO_SOSPECHOSO,
                'descripcion'  => 'La cámara detectó ' . $this->listarObjetos($sospechosos)
                                . " frente a {$casillero}.",
                'nivel_riesgo' => Anomalia::RIESGO_CRITICO,
                'confianza'    => round(max(array_column($sospechosos, 'confianza')), 4),
            ]);
            $this->copiarEvidencia($evidencia, $nueva);
        }
    }

    private function listarObjetos(array $sospechosos): string
    {
        $nombres = array_unique(array_map(fn ($o) => $o['nombre'] ?? $o['clase'], $sospechosos));

        return 'un objeto sospechoso (' . implode(', ', $nombres) . ')';
    }

    private function copiarEvidencia(Evidencia $evidencia, Anomalia $destino): void
    {
        Evidencia::create([
            'imagen_base64' => $evidencia->imagen_base64,
            'mime'          => $evidencia->mime,
            'tamano_bytes'  => $evidencia->tamano_bytes,
            'created_at'    => now(),
            'anomalias_id'  => $destino->id,
        ]);

        $destino->update(['evidencia_url' => "/api/mis-anomalias/{$destino->id}/evidencia"]);
    }

    // ==================================================
    // Generico (para las etapas siguientes: Isolation Forest)
    // ==================================================

    /**
     * Registra una anomalia de cualquier tipo y notifica.
     * $datos usa los mismos campos que el modelo Anomalia.
     */
    public function registrar(array $datos): Anomalia
    {
        $anomalia = $this->crear($datos);

        $this->notificador->anomalia($anomalia);

        return $anomalia;
    }

    private function crear(array $datos): Anomalia
    {
        // Dueno del casillero en este momento: es quien recibe la alerta y ve la foto.
        if (empty($datos['usuarios_id']) && ! empty($datos['casilleros_id'])) {
            $datos['usuarios_id'] = Casillero::find($datos['casilleros_id'])
                ?->asignacionActiva?->usuarios_id;
        }

        $anomalia = Anomalia::create(array_merge([
            'estado'     => Anomalia::PENDIENTE,
            'fecha_hora' => now(),
        ], $datos));

        // Solo las de sensores piden foto (las de IA ya vienen de una foto o de datos).
        if ($anomalia->origen === Anomalia::ORIGEN_REGLA) {
            $this->solicitarEvidencia($anomalia);
        }

        return $anomalia;
    }

    /**
     * Deja una orden TOMAR_FOTO para cada camara activa de la organizacion.
     * La ESP32-CAM la recoge con GET /api/dispositivo/ordenes y sube la foto
     * a POST /api/dispositivo/evidencia.
     */
    private function solicitarEvidencia(Anomalia $anomalia): void
    {
        if (! $anomalia->organizaciones_id) {
            return;
        }

        $posicion = $anomalia->casillero?->posicion ?? 0;

        $camaras = Dispositivo::camarasActivas($anomalia->organizaciones_id)->get();

        foreach ($camaras as $camara) {
            OrdenDispositivo::create([
                'tipo'            => OrdenDispositivo::TIPO_TOMAR_FOTO,
                'posicion'        => $posicion,
                'estado'          => OrdenDispositivo::PENDIENTE,
                'expira_en'       => now()->addSeconds(self::VIGENCIA_ORDEN_FOTO),
                'dispositivos_id' => $camara->id,
                'casilleros_id'   => $anomalia->casilleros_id,
                'anomalias_id'    => $anomalia->id,
            ]);
        }
    }
}
