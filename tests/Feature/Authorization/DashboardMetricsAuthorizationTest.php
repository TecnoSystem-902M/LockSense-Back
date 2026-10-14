<?php

namespace Tests\Feature\Authorization;

use App\Models\Rol;
use Illuminate\Support\Facades\DB;

/**
 * Pruebas de autorización y aislamiento de las métricas del Dashboard
 * (DashboardController::metrics) sobre GET /api/dashboard/metrics.
 *
 * El endpoint devuelve: usuarios_totales, casilleros_totales,
 * casilleros_bloqueados.
 * - USUARIO: 403.
 * - ADMINISTRADOR: conteos limitados a su organización.
 * - SUPERADMIN: conteos globales.
 *
 * Se usan datos en DOS organizaciones para detectar fugas entre ellas.
 * Ejecuta sobre SQLite en memoria (ver AuthorizationTestCase).
 */
class DashboardMetricsAuthorizationTest extends AuthorizationTestCase
{
    /**
     * Inserta casilleros de prueba para una organización.
     * `estado` es texto en el esquema real; el conteo de bloqueados usa 'Bloqueado'.
     */
    private function crearCasilleros(int $organizacionId, int $totales, int $bloqueados): void
    {
        for ($i = 0; $i < $totales; $i++) {
            DB::table('casilleros')->insert([
                'organizaciones_id' => $organizacionId,
                'numero'            => 'C'.$organizacionId.'-'.$i,
                'ubicacion'         => 'Zona',
                'estado'            => $i < $bloqueados ? 'Bloqueado' : 'Disponible',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function test_usuario_no_puede_consultar_metricas(): void
    {
        $this->actuandoComoRol(Rol::USUARIO);

        $this->getJson('/api/dashboard/metrics')->assertStatus(403);
    }

    public function test_admin_obtiene_solo_metricas_de_su_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        // Org A: el admin + 2 usuarios normales = 3 usuarios en A.
        $admin = $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);
        $this->crearUsuario(Rol::USUARIO, $orgA->id);
        $this->crearUsuario(Rol::USUARIO, $orgA->id);

        // Org B: 4 usuarios (no deben contarse para el admin de A).
        for ($i = 0; $i < 4; $i++) {
            $this->crearUsuario(Rol::USUARIO, $orgB->id);
        }

        // Casilleros: A = 5 totales (2 bloqueados); B = 7 totales (3 bloqueados).
        $this->crearCasilleros($orgA->id, 5, 2);
        $this->crearCasilleros($orgB->id, 7, 3);

        $resp = $this->getJson('/api/dashboard/metrics')->assertStatus(200);

        // Solo datos de la organización A.
        $resp->assertJson([
            'usuarios_totales'      => 3,   // admin + 2 usuarios de A
            'casilleros_totales'    => 5,
            'casilleros_bloqueados' => 2,
        ]);

        // Verificación anti-fuga explícita: nunca los totales globales.
        $this->assertNotSame(7, (int) $resp->json('usuarios_totales'));  // 3 (A) + 4 (B) = 7 global
        $this->assertNotSame(12, (int) $resp->json('casilleros_totales')); // 5 + 7 = 12 global
        $this->assertNotSame(5, (int) $resp->json('casilleros_bloqueados')); // 2 + 3 = 5 global
    }

    public function test_superadmin_obtiene_metricas_globales(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        // Superadmin en A + 1 usuario en A; 2 usuarios en B => 4 usuarios globales.
        $this->actuandoComoRol(Rol::SUPERADMIN, $orgA->id);
        $this->crearUsuario(Rol::USUARIO, $orgA->id);
        $this->crearUsuario(Rol::USUARIO, $orgB->id);
        $this->crearUsuario(Rol::USUARIO, $orgB->id);

        // Casilleros: A = 3 (1 bloqueado); B = 4 (2 bloqueados) => 7 totales, 3 bloqueados.
        $this->crearCasilleros($orgA->id, 3, 1);
        $this->crearCasilleros($orgB->id, 4, 2);

        $resp = $this->getJson('/api/dashboard/metrics')->assertStatus(200);

        $resp->assertJson([
            'usuarios_totales'      => 4,  // 1 superadmin + 1 (A) + 2 (B)
            'casilleros_totales'    => 7,
            'casilleros_bloqueados' => 3,
        ]);
    }
}
