<?php

namespace Tests\Feature\Authorization;

use App\Models\Rol;
use Database\Factories\AsignacionFactory;
use Database\Factories\CasilleroFactory;
use Database\Factories\CredencialRfidFactory;

/**
 * Pruebas de autorización y aislamiento del endpoint GET /api/casilleros
 * (CasilleroController::index). Ejecuta sobre SQLite en memoria.
 */
class CasilleroAuthorizationTest extends AuthorizationTestCase
{
    public function test_sin_token_recibe_401(): void
    {
        // Sin autenticar (no se usa actuandoComo*).
        $this->getJson('/api/casilleros')->assertStatus(401);
    }

    public function test_usuario_normal_recibe_403(): void
    {
        $this->actuandoComoRol(Rol::USUARIO);

        $this->getJson('/api/casilleros')->assertStatus(403);
    }

    public function test_admin_solo_ve_casilleros_de_su_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $cA1 = CasilleroFactory::new()->paraOrganizacion($orgA->id)->create();
        $cA2 = CasilleroFactory::new()->paraOrganizacion($orgA->id)->create();
        $cB  = CasilleroFactory::new()->paraOrganizacion($orgB->id)->create();

        $resp = $this->getJson('/api/casilleros')->assertStatus(200);

        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertContains($cA1->id, $ids);
        $this->assertContains($cA2->id, $ids);
        $this->assertNotContains($cB->id, $ids);
    }

    public function test_superadmin_ve_casilleros_de_varias_organizaciones(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::SUPERADMIN, $orgA->id);

        $cA = CasilleroFactory::new()->paraOrganizacion($orgA->id)->create();
        $cB = CasilleroFactory::new()->paraOrganizacion($orgB->id)->create();

        $resp = $this->getJson('/api/casilleros')->assertStatus(200);

        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertContains($cA->id, $ids);
        $this->assertContains($cB->id, $ids);
    }

    public function test_representa_casillero_asignado_y_sin_asignacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::SUPERADMIN, $orgA->id);

        // Casillero SIN asignación.
        $libre = CasilleroFactory::new()->paraOrganizacion($orgA->id)->create();

        // Casillero CON asignación activa + usuario + credencial RFID.
        $asignado = CasilleroFactory::new()->paraOrganizacion($orgA->id)->asignado()->create();
        $usuario  = $this->crearUsuario(Rol::USUARIO, $orgA->id);
        CredencialRfidFactory::new()->create([
            'usuarios_id' => $usuario->id,
            'uid'         => 'RFID-ABC123',
            'estado'      => true,
        ]);
        AsignacionFactory::new()->create([
            'usuarios_id'   => $usuario->id,
            'casilleros_id' => $asignado->id,
            'estado'        => true,
        ]);

        $resp = $this->getJson('/api/casilleros')->assertStatus(200);
        $porId = collect($resp->json())->keyBy('id');

        // Casillero libre: usuario y uid nulos.
        $this->assertNull($porId[$libre->id]['usuario']);
        $this->assertNull($porId[$libre->id]['uid_rfid']);
        $this->assertNull($porId[$libre->id]['asignacion']);

        // Casillero asignado: datos del usuario + UID real.
        $fila = $porId[$asignado->id];
        $this->assertNotNull($fila['usuario']);
        $this->assertSame($usuario->id, $fila['usuario']['id']);
        $this->assertStringContainsString($usuario->nombre, $fila['usuario']['nombre_completo']);
        $this->assertSame('RFID-ABC123', $fila['uid_rfid']);
        $this->assertNotNull($fila['asignacion']);
    }

    public function test_respuesta_no_expone_informacion_sensible(): void
    {
        $orgA = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::SUPERADMIN, $orgA->id);

        $asignado = CasilleroFactory::new()->paraOrganizacion($orgA->id)->asignado()->create();
        $usuario  = $this->crearUsuario(Rol::USUARIO, $orgA->id);
        AsignacionFactory::new()->create([
            'usuarios_id'   => $usuario->id,
            'casilleros_id' => $asignado->id,
            'estado'        => true,
        ]);

        $resp = $this->getJson('/api/casilleros')->assertStatus(200);
        $fila = collect($resp->json())->firstWhere('id', $asignado->id);

        // El objeto usuario no debe incluir la contraseña ni el hash.
        $this->assertArrayNotHasKey('contrasena', $fila['usuario']);
        $this->assertArrayNotHasKey('password', $fila['usuario']);
        // Verificación textual sobre todo el cuerpo por si acaso.
        $this->assertStringNotContainsString('contrasena', $resp->getContent());
    }
}
