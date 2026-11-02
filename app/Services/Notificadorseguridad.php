<?php

namespace App\Services;

use App\Models\Anomalia;
use App\Models\Notificacion;

/**
 * Notificaciones de seguridad (HU5, HU7).
 *
 * Solo se notifica al DUENO del casillero (el usuario que lo tenia asignado
 * cuando ocurrio la anomalia). El administrador no recibe notificaciones.
 *
 * Si la anomalia no es de un casillero con dueno (ej. intentos fallidos con
 * tarjetas desconocidas), queda registrada pero no se notifica a nadie.
 */
class NotificadorSeguridad
{
    public function anomalia(Anomalia $anomalia): void
    {
        if (! $anomalia->usuarios_id) {
            return;
        }

        Notificacion::create([
            'tipo'         => $this->tipoNotificacion($anomalia->tipo),
            'mensaje'      => $anomalia->descripcion,
            'leida'        => Notificacion::NO_LEIDA,
            'fecha_hora'   => now(),
            'usuarios_id'  => $anomalia->usuarios_id,
            'anomalias_id' => $anomalia->id,
        ]);
    }

    private function tipoNotificacion(string $tipoAnomalia): string
    {
        return match ($tipoAnomalia) {
            Anomalia::TIPO_APERTURA_NO_AUTORIZADA => Notificacion::TIPO_APERTURA_NO_AUTORIZADA,
            Anomalia::TIPO_INTENTOS_FALLIDOS      => Notificacion::TIPO_INTENTOS_FALLIDOS,
            default                               => Notificacion::TIPO_ANOMALIA,
        };
    }
}