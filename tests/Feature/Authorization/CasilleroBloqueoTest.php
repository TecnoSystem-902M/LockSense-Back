<?php

namespace Tests\Feature\Authorization;

use App\Models\Asignacion;
use App\Models\Casillero;
use App\Models\CredencialRfid;
use App\Models\EventoCasillero;
use App\Models\Rol;
use Database\Factories\AsignacionFactory;
use Database\Factories\CasilleroFactory;
use Database\Factories\CredencialRfidFactory;

/**
 * Pruebas de la Etapa 2: bloqueo/desbloqueo persistente de casilleros.
 * Endpoints:
 *   POST /api/casilleros/{id}/bloquear   { motivo_bloqueo }
 *   POST /api/casilleros/{id}/desbloquear
 * Ejecuta sobre SQLite en memoria (RefreshDatabase + migraciones de prueba).
 */
class CasilleroBloqueoTest extends AuthorizationTestCase
{
    /**
     * Crea un casillero asignado (asignación activa + credencial RFID) y
     * devuelve [casillero, usuario].
     */
    private function casilleroAsignado(int $organizacionId): array
    {
        $casillero = CasilleroFactory::new()->paraOrganizacion($organizacionId)->create();
        $usuario   = $this->crearUsuario(Rol::USUARIO, $organizacionId);

        CredencialRfidFactory::new()->create([
            'usuarios_id' => $usuario->id,
            'uid'         => 'RFID-TEST01',
            'estado'      => true,
        ]);
        AsignacionFactory::new()->create([
            'usuarios_id'   => $usuario->id,
            'casilleros_id' => $casillero->id,
            'estado'        => true,
        ]);

        return [$casillero, $usuario];
    }

    // ----------------------------------------------------------------
    // Casos funcionales
    // ----------------------------------------------------------------

    public function test_bloqueo_por_mantenimiento_de_casillero_libre(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $libre = CasilleroFactory::new()->paraOrganizacion($org->id)->create();

        $resp = $this->postJson("/api/casilleros/{$libre->id}/bloquear", [
            'motivo_bloqueo' => 'MANTENIMIENTO',
        ])->assertStatus(200);

        $this->assertTrue($resp->json('casillero.bloqueado'));
        $this->assertSame('MANTENIMIENTO', $resp->json('casillero.motivo_bloqueo'));
        $this->assertFalse($resp->json('casillero.ocupado'));

        $this->assertDatabaseHas('casilleros', [
            'id'             => $libre->id,
            'bloqueado'      => true,
            'motivo_bloqueo' => 'MANTENIMIENTO',
        ]);

        // Se registró el evento de bloqueo.
        $this->assertSame(1, EventoCasillero::query()
            ->where('casilleros_id', $libre->id)
            ->where('tipo_evento', 'BLOQUEO')
            ->count());
    }

    public function test_bloqueo_administrativo_de_casillero_asignado(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        [$casillero, $usuario] = $this->casilleroAsignado($org->id);

        $resp = $this->postJson("/api/casilleros/{$casillero->id}/bloquear", [
            'motivo_bloqueo' => 'ADMINISTRATIVO',
        ])->assertStatus(200);

        $this->assertTrue($resp->json('casillero.bloqueado'));
        $this->assertSame('ADMINISTRATIVO', $resp->json('casillero.motivo_bloqueo'));
        // Sigue ocupado: el bloqueo no toca la asignación.
        $this->assertTrue($resp->json('casillero.ocupado'));
        $this->assertSame($usuario->id, $resp->json('casillero.usuario.id'));

        // La asignación y la credencial permanecen intactas.
        $this->assertDatabaseHas('asignaciones', [
            'casilleros_id' => $casillero->id,
            'usuarios_id'   => $usuario->id,
            'estado'        => true,
        ]);
        $this->assertDatabaseHas('credenciales_rfid', [
            'usuarios_id' => $usuario->id,
            'uid'         => 'RFID-TEST01',
            'estado'      => true,
        ]);
    }

    public function test_desbloqueo_conserva_asignacion_y_credencial(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        [$casillero, $usuario] = $this->casilleroAsignado($org->id);

        // Bloqueo administrativo previo.
        $this->postJson("/api/casilleros/{$casillero->id}/bloquear", [
            'motivo_bloqueo' => 'ADMINISTRATIVO',
        ])->assertStatus(200);

        // Desbloqueo.
        $resp = $this->postJson("/api/casilleros/{$casillero->id}/desbloquear")
            ->assertStatus(200);

        $this->assertFalse($resp->json('casillero.bloqueado'));
        $this->assertNull($resp->json('casillero.motivo_bloqueo'));
        // La ocupación se recupera de la asignación activa.
        $this->assertTrue($resp->json('casillero.ocupado'));
        $this->assertSame($usuario->id, $resp->json('casillero.usuario.id'));

        $this->assertDatabaseHas('casilleros', [
            'id'             => $casillero->id,
            'bloqueado'      => false,
            'motivo_bloqueo' => null,
        ]);

        // La asignación y la credencial NO fueron liberadas ni eliminadas.
        $this->assertDatabaseHas('asignaciones', [
            'casilleros_id' => $casillero->id,
            'usuarios_id'   => $usuario->id,
            'estado'        => true,
        ]);
        $this->assertSame(1, Asignacion::query()
            ->where('casilleros_id', $casillero->id)->count());
        $this->assertSame(1, CredencialRfid::query()
            ->where('usuarios_id', $usuario->id)->count());
    }

    public function test_rechaza_bloqueo_administrativo_sin_asignacion(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $libre = CasilleroFactory::new()->paraOrganizacion($org->id)->create();

        $this->postJson("/api/casilleros/{$libre->id}/bloquear", [
            'motivo_bloqueo' => 'ADMINISTRATIVO',
        ])->assertStatus(422);

        // No se persistió ningún bloqueo.
        $this->assertDatabaseHas('casilleros', [
            'id'        => $libre->id,
            'bloqueado' => false,
        ]);
    }

    public function test_rechaza_motivo_invalido(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();

        $this->postJson("/api/casilleros/{$casillero->id}/bloquear", [
            'motivo_bloqueo' => 'OTRO',
        ])->assertStatus(422);
    }

    // ----------------------------------------------------------------
    // Autorización y aislamiento por organización
    // ----------------------------------------------------------------

    public function test_sin_token_recibe_401(): void
    {
        $this->postJson('/api/casilleros/1/bloquear', [
            'motivo_bloqueo' => 'MANTENIMIENTO',
        ])->assertStatus(401);

        $this->postJson('/api/casilleros/1/desbloquear')->assertStatus(401);
    }

    public function test_usuario_normal_recibe_403(): void
    {
        $org = $this->crearOrganizacion();
        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();

        $this->actuandoComoRol(Rol::USUARIO, $org->id);

        $this->postJson("/api/casilleros/{$casillero->id}/bloquear", [
            'motivo_bloqueo' => 'MANTENIMIENTO',
        ])->assertStatus(403);

        $this->postJson("/api/casilleros/{$casillero->id}/desbloquear")
            ->assertStatus(403);

        // No se tocó nada.
        $this->assertDatabaseHas('casilleros', [
            'id'        => $casillero->id,
            'bloqueado' => false,
        ]);
    }

    public function test_admin_no_puede_bloquear_casillero_de_otra_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $ajeno = CasilleroFactory::new()->paraOrganizacion($orgB->id)->create();

        // 404: no se revela la existencia de un casillero de otra organización.
        $this->postJson("/api/casilleros/{$ajeno->id}/bloquear", [
            'motivo_bloqueo' => 'MANTENIMIENTO',
        ])->assertStatus(404);

        $this->assertDatabaseHas('casilleros', [
            'id'        => $ajeno->id,
            'bloqueado' => false,
        ]);
    }

    public function test_superadmin_puede_bloquear_en_cualquier_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::SUPERADMIN, $orgA->id);

        $casilleroB = CasilleroFactory::new()->paraOrganizacion($orgB->id)->create();

        $this->postJson("/api/casilleros/{$casilleroB->id}/bloquear", [
            'motivo_bloqueo' => 'MANTENIMIENTO',
        ])->assertStatus(200);

        $this->assertDatabaseHas('casilleros', [
            'id'             => $casilleroB->id,
            'bloqueado'      => true,
            'motivo_bloqueo' => 'MANTENIMIENTO',
        ]);
    }
}
