<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Devuelve las métricas dinámicas del Dashboard.
     * Consultas de solo lectura sobre PostgreSQL.
     *
     * Autorización:
     * - USUARIO: 403 (no accede a métricas administrativas).
     * - ADMINISTRADOR: métricas limitadas a su organización.
     * - SUPERADMIN: métricas globales.
     */
    public function metrics(Request $request)
    {
        $user = $request->user();

        $esSuperadmin    = $user && (int) $user->roles_id === Rol::SUPERADMIN;
        $esAdministrador = $user && (int) $user->roles_id === Rol::ADMINISTRADOR;

        if (!$esSuperadmin && !$esAdministrador) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $usuarios   = DB::table('usuarios');
        $casTotales = DB::table('casilleros');
        $casBloq    = DB::table('casilleros')->where('estado', 'Bloqueado');

        // Administrador: acotar a su organización.
        if (!$esSuperadmin) {
            $orgId = $user->organizaciones_id;
            $usuarios->where('organizaciones_id', $orgId);
            $casTotales->where('organizaciones_id', $orgId);
            $casBloq->where('organizaciones_id', $orgId);
        }

        return response()->json([
            'usuarios_totales'      => $usuarios->count(),
            'casilleros_totales'    => $casTotales->count(),
            'casilleros_bloqueados' => $casBloq->count(),
        ]);
    }
}
