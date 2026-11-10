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
 *
 * Además de guardar la notificación en la BD, manda una notificación push
 * a los dispositivos donde el dueño aceptó las alertas.
 */
class NotificadorSeguridad
{
    // Pantalla que se abre al tocar la notificación.
    private const URL_ACTIVIDAD = '/usuario/actividad';

    // Título corto de la notificación según el tipo de anomalía.
    private const TITULOS = [
        Anomalia::TIPO_APERTURA_NO_AUTORIZADA    => 'Apertura no autorizada',
        Anomalia::TIPO_INTENTOS_FALLIDOS         => 'Intentos de acceso fallidos',
        Anomalia::TIPO_VIBRACION                 => 'Golpe en tu casillero',
        Anomalia::TIPO_PUERTA_ABIERTA_PROLONGADA => 'Puerta abierta mucho tiempo',
        Anomalia::TIPO_PERSONAS_MULTIPLES        => 'Varias personas en tu casillero',
        Anomalia::TIPO_OBJETO_SOSPECHOSO         => 'Objeto sospechoso detectado',
        Anomalia::TIPO_PATRON_INUSUAL            => 'Acceso inusual',
    ];

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

        // Push: se manda DESPUÉS de responder (al ESP32 o a la cámara),
        // para no hacer más lenta la petición.
        $usuarioId = $anomalia->usuarios_id;
        $mensaje   = $this->mensajePush($anomalia);

        dispatch(fn () => app(EnviadorPush::class)->enviarAUsuario($usuarioId, $mensaje))
            ->afterResponse();
    }

    private function mensajePush(Anomalia $anomalia): array
    {
        $numero = $anomalia->casillero?->numero;
        $tipo   = self::TITULOS[$anomalia->tipo] ?? 'Alerta de seguridad';

        return [
            'titulo'   => $numero ? "Alerta en tu casillero {$numero}" : 'Alerta en tu casillero',
            'cuerpo'   => "{$tipo}: {$anomalia->descripcion}",
            'url'      => self::URL_ACTIVIDAD,
            'etiqueta' => 'anomalia-' . $anomalia->id,
        ];
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
