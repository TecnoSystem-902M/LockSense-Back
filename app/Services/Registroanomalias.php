<?php

namespace App\Services;

use App\Models\Acceso;
use App\Models\Anomalia;
use App\Models\Casillero;
use App\Models\Dispositivo;
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

    public function __construct(private NotificadorSeguridad $notificador)
    {
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
    // Generico (para las etapas siguientes: YOLO, Isolation Forest)
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