<?php

namespace App\Http\Controllers;

use App\Models\Organizacion;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LockSense - Dashboard del superadministrador.
 *
 * GET /api/superadmin/dashboard
 *   Tarjetas: organizaciones, usuarios totales y administradores.
 *   Gráficas: organizaciones registradas por mes (últimos 6) y por tipo.
 */
class SuperAdminDashboardController extends Controller
{
    private const ROL_SUPERADMIN    = 1;
    private const ROL_ADMINISTRADOR = 2;
    private const ROL_USUARIO       = 3;

    // Organización interna del superadmin: no cuenta en las estadísticas.
    private const TIPO_SISTEMA = 'Sistema';

    private const MESES_GRAFICA = 6;

    private const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
                           'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public function resumen(Request $request): JsonResponse
    {
        abort_unless((int) $request->user()->roles_id === self::ROL_SUPERADMIN, 403,
            'Solo el superadministrador puede ver este panel.');

        return response()->json([
            'tarjetas' => [
                'organizaciones'  => $this->organizaciones()->count(),
                'usuarios'        => Usuario::query()
                    ->whereIn('roles_id', [self::ROL_ADMINISTRADOR, self::ROL_USUARIO])
                    ->count(),
                'administradores' => Usuario::query()
                    ->where('roles_id', self::ROL_ADMINISTRADOR)
                    ->count(),
            ],
            'por_mes'  => $this->organizacionesPorMes(),
            'por_tipo' => $this->organizacionesPorTipo(),
        ]);
    }

    /** Organizaciones reales (sin la del sistema). */
    private function organizaciones(): Builder
    {
        return Organizacion::query()->where(function ($q) {
            $q->whereNull('tipo')->orWhere('tipo', '!=', self::TIPO_SISTEMA);
        });
    }

    /**
     * Organizaciones registradas en cada uno de los últimos 6 meses
     * (incluido el actual). Los meses sin registros salen en 0.
     */
    private function organizacionesPorMes(): array
    {
        $inicio = now()->startOfMonth()->subMonths(self::MESES_GRAFICA - 1);

        $conteo = $this->organizaciones()
            ->where('created_at', '>=', $inicio)
            ->get(['id', 'created_at'])
            ->countBy(fn (Organizacion $org) => Carbon::parse($org->created_at)->format('Y-m'));

        $meses = [];

        for ($i = 0; $i < self::MESES_GRAFICA; $i++) {
            $mes = $inicio->copy()->addMonths($i);

            $meses[] = [
                'mes'   => self::MESES[$mes->month - 1] . ' ' . $mes->format('y'),   // "Nov 26"
                'total' => $conteo->get($mes->format('Y-m'), 0),
            ];
        }

        return $meses;
    }

    /** Cuántas organizaciones hay de cada tipo (Escuela, Empresa, ...). */
    private function organizacionesPorTipo(): array
    {
        return $this->organizaciones()
            ->pluck('tipo')
            ->map(fn ($tipo) => $tipo ? mb_convert_case(trim($tipo), MB_CASE_TITLE) : 'Sin tipo')
            ->countBy()
            ->sortDesc()
            ->map(fn ($total, $tipo) => ['tipo' => $tipo, 'total' => $total])
            ->values()
            ->all();
    }
}