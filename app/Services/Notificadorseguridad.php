<?php

namespace App\Services;

use App\Models\Casillero;
use App\Models\Dispositivo;
use App\Models\Notificacion;
use App\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Genera las notificaciones de seguridad (HU5) para:
 *   - Los administradores de la organizacion (roles_id = 2).
 *   - El dueno del casillero, cuando se sabe cual es.
 */
class NotificadorSeguridad
{
    const ROL_ADMINISTRADOR = 2;

    public function intentosFallidos(Dispositivo $dispositivo, int $intentos, int $segundosBloqueo): void
    {
        $mensaje = "Se bloqueó temporalmente el lector de casilleros por {$intentos} intentos "
                 . "de acceso fallidos. El bloqueo dura {$segundosBloqueo} segundos.";

        $this->notificar(
            $this->administradores($dispositivo->organizaciones_id),
            Notificacion::TIPO_INTENTOS_FALLIDOS,
            $mensaje
        );
    }

    public function aperturaNoAutorizada(Dispositivo $dispositivo, ?Casillero $casillero): void
    {
        $nombre = $casillero ? "el casillero {$casillero->numero}" : 'un casillero';
        $mensaje = "Se detectó la apertura de {$nombre} sin un acceso autorizado.";

        $destinatarios = $this->administradores($dispositivo->organizaciones_id);

        $dueno = $casillero?->asignacionActiva?->usuarios_id;
        if ($dueno) {
            $destinatarios->push($dueno);
        }

        $this->notificar($destinatarios, Notificacion::TIPO_APERTURA_NO_AUTORIZADA, $mensaje);
    }

    private function administradores(int $organizacionId): Collection
    {
        return Usuario::where('organizaciones_id', $organizacionId)
            ->where('roles_id', self::ROL_ADMINISTRADOR)
            ->pluck('id');
    }

    private function notificar(Collection $usuariosIds, string $tipo, string $mensaje): void
    {
        foreach ($usuariosIds->unique() as $usuarioId) {
            Notificacion::create([
                'tipo'        => $tipo,
                'mensaje'     => $mensaje,
                'leida'       => Notificacion::NO_LEIDA,
                'fecha_hora'  => now(),
                'usuarios_id' => $usuarioId,
            ]);
        }
    }
}