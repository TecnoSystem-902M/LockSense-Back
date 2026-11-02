<?php

namespace App\Services;

use App\Models\Anomalia;
use App\Models\Casillero;
use App\Models\Dispositivo;
use App\Models\Notificacion;
use App\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Genera las notificaciones de seguridad (HU5, HU7) para:
 *   - Los administradores de la organizacion (roles_id = 2).
 *   - El dueno del casillero, cuando se sabe cual es.
 *
 * Si la notificacion viene de una anomalia, queda ligada con anomalias_id.
 */
class NotificadorSeguridad
{
    const ROL_ADMINISTRADOR = 2;

    public function intentosFallidos(Dispositivo $dispositivo, int $intentos, int $segundosBloqueo,
                                     ?Anomalia $anomalia = null): void
    {
        $mensaje = "Se bloqueó temporalmente el lector de casilleros por {$intentos} intentos "
                 . "de acceso fallidos. El bloqueo dura {$segundosBloqueo} segundos.";

        $this->notificar(
            $this->administradores($dispositivo->organizaciones_id),
            Notificacion::TIPO_INTENTOS_FALLIDOS,
            $mensaje,
            $anomalia
        );
    }

    public function aperturaNoAutorizada(Dispositivo $dispositivo, ?Casillero $casillero,
                                         ?Anomalia $anomalia = null): void
    {
        $nombre = $casillero ? "el casillero {$casillero->numero}" : 'un casillero';
        $mensaje = "Se detectó la apertura de {$nombre} sin un acceso autorizado.";

        $this->notificar(
            $this->destinatarios($dispositivo->organizaciones_id, $casillero),
            Notificacion::TIPO_APERTURA_NO_AUTORIZADA,
            $mensaje,
            $anomalia
        );
    }

    /**
     * Notificacion generica de una anomalia (para los tipos nuevos de HU7:
     * vibracion, puerta abierta mucho tiempo, IA, etc.).
     */
    public function anomalia(Anomalia $anomalia): void
    {
        $this->notificar(
            $this->destinatarios($anomalia->organizaciones_id, $anomalia->casillero),
            Notificacion::TIPO_ANOMALIA,
            $anomalia->descripcion,
            $anomalia
        );
    }

    /** Administradores de la organizacion + dueno del casillero (si lo hay). */
    private function destinatarios(int $organizacionId, ?Casillero $casillero): Collection
    {
        $destinatarios = $this->administradores($organizacionId);

        $dueno = $casillero?->asignacionActiva?->usuarios_id;
        if ($dueno) {
            $destinatarios->push($dueno);
        }

        return $destinatarios;
    }

    private function administradores(int $organizacionId): Collection
    {
        return Usuario::where('organizaciones_id', $organizacionId)
            ->where('roles_id', self::ROL_ADMINISTRADOR)
            ->pluck('id');
    }

    private function notificar(Collection $usuariosIds, string $tipo, string $mensaje,
                               ?Anomalia $anomalia = null): void
    {
        foreach ($usuariosIds->unique() as $usuarioId) {
            Notificacion::create([
                'tipo'         => $tipo,
                'mensaje'      => $mensaje,
                'leida'        => Notificacion::NO_LEIDA,
                'fecha_hora'   => now(),
                'usuarios_id'  => $usuarioId,
                'anomalias_id' => $anomalia?->id,
            ]);
        }
    }
}