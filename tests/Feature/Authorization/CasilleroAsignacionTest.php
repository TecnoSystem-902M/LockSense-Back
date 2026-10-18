<?php

namespace Tests\Feature\Authorization;

use App\Models\Asignacion;
use App\Models\CredencialRfid;
use App\Models\Rol;
use App\Models\UsuarioCrud;
use Database\Factories\AsignacionFactory;
use Database\Factories\CasilleroFactory;
use Database\Factories\CredencialRfidFactory;

/**
 * Pruebas de la Etapa 3: asignación, consulta de usuario y liberación.
 * Endpoints:
 *   GET  /api/casilleros/{id}/usuarios-asignables
 *   POST /api/casilleros/{id}/asignar       { usuarios_id }
 *   GET  /api/casilleros/{id}/asignacion
 *   POST /api/casilleros/{id}/liberar
 * Ejecuta sobre SQLite en memoria (RefreshDatabase + migraciones de prueba).
 */
class CasilleroAsignacionTest extends AuthorizationTestCase
{
    /** Crea un usuario USUARIO activo en la organización dada. */
    private function usuarioNormal(int $organizacionId): UsuarioCrud
    {
        $u = $this->crearUsuario(Rol::USUARIO, $organizacionId);
        return UsuarioCrud::query()->find($u->id);
    }

    // ----------------------------------------------------------------
    // Asignación
    // ----------------------------------------------------------------

    public function test_asignacion_valida_crea_registro_activo(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();
        $usuario   = $this->usuarioNormal($org->id);

        $resp = $this->postJson("/api/casilleros/{$casillero->id}/asignar", [
            'usuarios_id' => $usuario->id,
        ])->assertStatus(201);

        $this->assertTrue($resp->json('casillero.ocupado'));
        $this->assertSame($usuario->id, $resp->json('casillero.usuario.id'));

        $this->assertDatabaseHas('asignaciones', [
            'casilleros_id' => $casillero->id,
            'usuarios_id'   => $usuario->id,
            'estado'        => true,
        ]);
        // Evento registrado.
        $this->assertDatabaseHas('eventos_casillero', [
            'casilleros_id' => $casillero->id,
            'tipo_evento'   => 'ASIGNACION',
        ]);
    }

    public function test_asignacion_rechaza_campos_invalidos(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();

        // Falta usuarios_id.
        $this->postJson("/api/casilleros/{$casillero->id}/asignar", [])
            ->assertStatus(422);
    }

    public function test_asignacion_rechaza_usuario_con_rol_incorrecto(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();
        // Objetivo con rol ADMINISTRADOR (no USUARIO).
        $adminObjetivo = $this->crearUsuario(Rol::ADMINISTRADOR, $org->id);

        $this->postJson("/api/casilleros/{$casillero->id}/asignar", [
            'usuarios_id' => $adminObjetivo->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('asignaciones', [
            'casilleros_id' => $casillero->id,
            'usuarios_id'   => $adminObjetivo->id,
        ]);
    }

    public function test_asignacion_rechaza_casillero_bloqueado(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()
            ->paraOrganizacion($org->id)
            ->bloqueadoPor('MANTENIMIENTO')
            ->create();
        $usuario = $this->usuarioNormal($org->id);

        $this->postJson("/api/casilleros/{$casillero->id}/asignar", [
            'usuarios_id' => $usuario->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('asignaciones', [
            'casilleros_id' => $casillero->id,
        ]);
    }

    public function test_asignacion_rechaza_casillero_ya_ocupado(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();
        $ocupante  = $this->usuarioNormal($org->id);
        AsignacionFactory::new()->create([
            'usuarios_id'   => $ocupante->id,
            'casilleros_id' => $casillero->id,
            'estado'        => true,
        ]);

        $otro = $this->usuarioNormal($org->id);

        $this->postJson("/api/casilleros/{$casillero->id}/asignar", [
            'usuarios_id' => $otro->id,
        ])->assertStatus(409);
    }

    public function test_admin_no_puede_asignar_casillero_de_otra_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $ajeno   = CasilleroFactory::new()->paraOrganizacion($orgB->id)->create();
        $usuario = $this->usuarioNormal($orgB->id);

        $this->postJson("/api/casilleros/{$ajeno->id}/asignar", [
            'usuarios_id' => $usuario->id,
        ])->assertStatus(404);
    }

    public function test_admin_no_puede_asignar_usuario_de_otra_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $casillero  = CasilleroFactory::new()->paraOrganizacion($orgA->id)->create();
        $usuarioOtro = $this->usuarioNormal($orgB->id);

        $this->postJson("/api/casilleros/{$casillero->id}/asignar", [
            'usuarios_id' => $usuarioOtro->id,
        ])->assertStatus(422);
    }

    public function test_usuario_normal_no_puede_asignar(): void
    {
        $org = $this->crearOrganizacion();
        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();
        $objetivo  = $this->usuarioNormal($org->id);

        $this->actuandoComoRol(Rol::USUARIO, $org->id);

        $this->postJson("/api/casilleros/{$casillero->id}/asignar", [
            'usuarios_id' => $objetivo->id,
        ])->assertStatus(403);
    }

    // ----------------------------------------------------------------
    // Usuarios asignables
    // ----------------------------------------------------------------

    public function test_usuarios_asignables_solo_usuarios_de_la_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $admin = $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($orgA->id)->create();

        $uA   = $this->usuarioNormal($orgA->id);        // elegible
        $uB   = $this->usuarioNormal($orgB->id);        // otra org, no
        $adm2 = $this->crearUsuario(Rol::ADMINISTRADOR, $orgA->id); // rol no USUARIO

        $resp = $this->getJson("/api/casilleros/{$casillero->id}/usuarios-asignables")
            ->assertStatus(200);

        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertContains($uA->id, $ids);
        $this->assertNotContains($uB->id, $ids);
        $this->assertNotContains($adm2->id, $ids);
        $this->assertNotContains($admin->id, $ids); // no a sí mismo
    }

    // ----------------------------------------------------------------
    // Consulta de usuario
    // ----------------------------------------------------------------

    public function test_consulta_usuario_devuelve_uid_real(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();
        $usuario   = $this->usuarioNormal($org->id);

        CredencialRfidFactory::new()->create([
            'usuarios_id' => $usuario->id,
            'uid'         => 'RFID-REAL-9',
            'estado'      => true,
        ]);
        AsignacionFactory::new()->create([
            'usuarios_id'   => $usuario->id,
            'casilleros_id' => $casillero->id,
            'estado'        => true,
        ]);

        $resp = $this->getJson("/api/casilleros/{$casillero->id}/asignacion")
            ->assertStatus(200);

        $this->assertSame($usuario->id, $resp->json('usuario.id'));
        $this->assertSame('RFID-REAL-9', $resp->json('uid_rfid'));
        $this->assertTrue($resp->json('ocupado'));
    }

    public function test_consulta_usuario_sin_asignacion_devuelve_404(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();

        $this->getJson("/api/casilleros/{$casillero->id}/asignacion")
            ->assertStatus(404);
    }

    public function test_consulta_usuario_sin_credencial_uid_nulo(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();
        $usuario   = $this->usuarioNormal($org->id);
        AsignacionFactory::new()->create([
            'usuarios_id'   => $usuario->id,
            'casilleros_id' => $casillero->id,
            'estado'        => true,
        ]);

        $resp = $this->getJson("/api/casilleros/{$casillero->id}/asignacion")
            ->assertStatus(200);

        $this->assertNull($resp->json('uid_rfid'));
    }

    // ----------------------------------------------------------------
    // Liberación
    // ----------------------------------------------------------------

    public function test_liberar_cierra_asignacion_sin_borrar_usuario_ni_credencial(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();
        $usuario   = $this->usuarioNormal($org->id);

        CredencialRfidFactory::new()->create([
            'usuarios_id' => $usuario->id,
            'uid'         => 'RFID-KEEP-1',
            'estado'      => true,
        ]);
        $asignacion = AsignacionFactory::new()->create([
            'usuarios_id'   => $usuario->id,
            'casilleros_id' => $casillero->id,
            'estado'        => true,
        ]);

        $resp = $this->postJson("/api/casilleros/{$casillero->id}/liberar")
            ->assertStatus(200);

        // El casillero ya no está ocupado.
        $this->assertFalse($resp->json('casillero.ocupado'));
        $this->assertNull($resp->json('casillero.usuario'));

        // La asignación se cerró (baja lógica), NO se borró el historial.
        $this->assertDatabaseHas('asignaciones', [
            'id'     => $asignacion->id,
            'estado' => false,
        ]);
        $cerrada = Asignacion::query()->find($asignacion->id);
        $this->assertNotNull($cerrada->fecha_liberacion);

        // El usuario NO se borra ni desactiva.
        $this->assertDatabaseHas('usuarios', [
            'id'     => $usuario->id,
            'estado' => true,
        ]);
        // La credencial RFID NO se borra ni desvincula.
        $this->assertDatabaseHas('credenciales_rfid', [
            'usuarios_id' => $usuario->id,
            'uid'         => 'RFID-KEEP-1',
            'estado'      => true,
        ]);
        $this->assertSame(1, CredencialRfid::query()
            ->where('usuarios_id', $usuario->id)->count());

        // Evento de liberación registrado.
        $this->assertDatabaseHas('eventos_casillero', [
            'casilleros_id' => $casillero->id,
            'tipo_evento'   => 'LIBERACION',
        ]);
    }

    public function test_liberar_conserva_el_bloqueo(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        // Casillero asignado y además bloqueado administrativamente.
        $casillero = CasilleroFactory::new()
            ->paraOrganizacion($org->id)
            ->bloqueadoPor('ADMINISTRATIVO')
            ->create();
        $usuario = $this->usuarioNormal($org->id);
        AsignacionFactory::new()->create([
            'usuarios_id'   => $usuario->id,
            'casilleros_id' => $casillero->id,
            'estado'        => true,
        ]);

        $resp = $this->postJson("/api/casilleros/{$casillero->id}/liberar")
            ->assertStatus(200);

        // Deja de estar ocupado pero sigue bloqueado.
        $this->assertFalse($resp->json('casillero.ocupado'));
        $this->assertTrue($resp->json('casillero.bloqueado'));
        $this->assertSame('ADMINISTRATIVO', $resp->json('casillero.motivo_bloqueo'));

        $this->assertDatabaseHas('casilleros', [
            'id'             => $casillero->id,
            'bloqueado'      => true,
            'motivo_bloqueo' => 'ADMINISTRATIVO',
        ]);
    }

    public function test_liberar_sin_asignacion_activa_devuelve_409(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();

        $this->postJson("/api/casilleros/{$casillero->id}/liberar")
            ->assertStatus(409);
    }

    public function test_liberar_requiere_rol_administrativo(): void
    {
        $org = $this->crearOrganizacion();
        $casillero = CasilleroFactory::new()->paraOrganizacion($org->id)->create();

        $this->actuandoComoRol(Rol::USUARIO, $org->id);

        $this->postJson("/api/casilleros/{$casillero->id}/liberar")
            ->assertStatus(403);
    }

    public function test_endpoints_requieren_autenticacion(): void
    {
        $this->getJson('/api/casilleros/1/usuarios-asignables')->assertStatus(401);
        $this->postJson('/api/casilleros/1/asignar', ['usuarios_id' => 1])->assertStatus(401);
        $this->getJson('/api/casilleros/1/asignacion')->assertStatus(401);
        $this->postJson('/api/casilleros/1/liberar')->assertStatus(401);
    }
}
