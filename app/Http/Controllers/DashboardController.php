<?php

namespace App\Http\Controllers;

use App\Models\Acceso;
use App\Models\Casillero;
use App\Models\Rol;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const DIAS_TENDENCIA = 7;

    private const DIAS_SEMANA = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    /**
     * Métricas del Dashboard (todo con Eloquent).
     *
     * Autorización:
     * - USUARIO: 403.
     * - ADMINISTRADOR: solo su organización.
     * - SUPERADMIN: global.
     *
     * Devuelve:
     *   usuarios_totales, casilleros_totales, casilleros_bloqueados  (tarjetas)
     *   distribucion: { asignados, sin_asignar, bloqueados, total }   (dona)
     *   tendencia:    [ { fecha, dia, abiertos, cerrados }, ... ]     (líneas, 7 días)
     */
    public function metrics(Request $request)
    {
        $user = $request->user();

        $esSuperadmin    = $user && (int) $user->roles_id === Rol::SUPERADMIN;
        $esAdministrador = $user && (int) $user->roles_id === Rol::ADMINISTRADOR;

        if (! $esSuperadmin && ! $esAdministrador) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        // null = todas las organizaciones (superadmin)
        $orgId = $esSuperadmin ? null : $user->organizaciones_id;

        $distribucion = $this->distribucion($orgId);

        return response()->json([
            'usuarios_totales'      => Usuario::query()
                ->when($orgId, fn ($q) => $q->where('organizaciones_id', $orgId))
                ->count(),
            'casilleros_totales'    => $distribucion['total'],
            'casilleros_bloqueados' => $distribucion['bloqueados'],
            'distribucion'          => $distribucion,
            'tendencia'             => $this->tendencia($orgId, $distribucion['total']),
        ]);
    }

    /** Casilleros de la organización (o de todas si $orgId es null). */
    private function casilleros(?int $orgId): Builder
    {
        return Casillero::query()
            ->when($orgId, fn ($q) => $q->where('organizaciones_id', $orgId));
    }

    /**
     * Cada casillero cae en UNA sola categoría:
     *   bloqueado (tiene bloqueo activo) > asignado (asignación activa) > sin asignar.
     */
    private function distribucion(?int $orgId): array
    {
        $casilleros = $this->casilleros($orgId)
            ->with(['asignacionActiva', 'bloqueoActivo'])
            ->get();

        $bloqueados = $casilleros->filter(fn (Casillero $c) => $c->bloqueoActivo)->count();
        $asignados  = $casilleros->filter(fn (Casillero $c) => ! $c->bloqueoActivo && $c->asignacionActiva)->count();

        return [
            'asignados'   => $asignados,
            'sin_asignar' => $casilleros->count() - $asignados - $bloqueados,
            'bloqueados'  => $bloqueados,
            'total'       => $casilleros->count(),
        ];
    }

    /**
     * Últimos 7 días (incluido hoy):
     *   abiertos = casilleros que se abrieron al menos una vez ese día
     *              (con un acceso autorizado, por RFID o rostro).
     *   cerrados = total de casilleros - abiertos.
     */
    private function tendencia(?int $orgId, int $totalCasilleros): array
    {
        $inicio = now()->startOfDay()->subDays(self::DIAS_TENDENCIA - 1);

        // [ 'Y-m-d' => cantidad de casilleros distintos abiertos ese día ]
        $abiertosPorDia = Acceso::query()
            ->where('resultado', Acceso::AUTORIZADO)
            ->whereNotNull('casilleros_id')
            ->where('fecha_hora', '>=', $inicio)
            ->when($orgId, fn ($q) => $q->whereHas('casillero', fn ($c) => $c->where('organizaciones_id', $orgId)))
            ->get(['casilleros_id', 'fecha_hora'])
            ->groupBy(fn (Acceso $a) => Carbon::parse($a->fecha_hora)->format('Y-m-d'))
            ->map(fn ($accesos) => $accesos->pluck('casilleros_id')->unique()->count());

        $dias = [];

        for ($i = 0; $i < self::DIAS_TENDENCIA; $i++) {
            $fecha    = $inicio->copy()->addDays($i);
            $abiertos = min($abiertosPorDia->get($fecha->format('Y-m-d'), 0), $totalCasilleros);

            $dias[] = [
                'fecha'    => $fecha->format('d/m/Y'),
                'dia'      => self::DIAS_SEMANA[$fecha->dayOfWeek],
                'abiertos' => $abiertos,
                'cerrados' => $totalCasilleros - $abiertos,
            ];
        }

        return $dias;
    }
}