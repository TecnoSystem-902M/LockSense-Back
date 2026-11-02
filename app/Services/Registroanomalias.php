<?php

namespace App\Services;

use App\Models\Anomalia;
use App\Models\Casillero;
use App\Models\Dispositivo;

/**
 * HU7: registra anomalias en la tabla `anomalias` y genera la alerta
 * (notificacion a administradores y al dueno del casillero).
 *
 * Toda deteccion (sensores, YOLO, Isolation Forest) pasa por aqui, para que
 * todas queden guardadas igual y generen su notificacion.
 */
class RegistroAnomalias
{
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

        $this->notificador->aperturaNoAutorizada($dispositivo, $casillero, $anomalia);

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

        $this->notificador->intentosFallidos($dispositivo, $intentos, $segundosBloqueo, $anomalia);

        return $anomalia;
    }

    // ==================================================
    // Generico (para las etapas siguientes: vibracion, YOLO, Isolation Forest)
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
        return Anomalia::create(array_merge([
            'estado'     => Anomalia::PENDIENTE,
            'fecha_hora' => now(),
        ], $datos));
    }
}