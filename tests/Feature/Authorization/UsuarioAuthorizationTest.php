<?php

namespace Tests\Feature\Authorization;

use App\Models\Rol;
use Illuminate\Support\Str;

/**
 * Pruebas de autorización del módulo de usuarios (UsuarioController),
 * sobre las rutas protegidas por auth:sanctum:
 *   GET    /api/usuarios
 *   POST   /api/usuarios
 *   PUT    /api/usuarios/{id}
 *   DELETE /api/usuarios/{id}
 *   GET    /api/roles
 *   GET    /api/organizaciones
 *
 * Se ejecutan sobre SQLite en memoria (ver AuthorizationTestCase).
 */
class UsuarioAuthorizationTest extends AuthorizationTestCase
{
    /**
     * Construye un payload válido para crear un usuario (contrato de la API).
     */
    private function payloadUsuario(array $overrides = []): array
    {
        return array_merge([
            'nombre'           => 'Nombre',
            'apellido_paterno' => 'Apellido',
            'apellido_materno' => 'Materno',
            'identificador'    => Str::uuid()->toString(),
            'correo'           => 'nuevo_'.Str::random(6).'@ejemplo.com',
            'contrasena'       => 'password123',
            'telefono'         => '1234567890',
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // USUARIO (rol 3): sin acceso administrativo
    // ---------------------------------------------------------------

    public function test_usuario_no_puede_listar_usuarios(): void
    {
        $this->actuandoComoRol(Rol::USUARIO);

        $this->getJson('/api/usuarios')->assertStatus(403);
    }

    public function test_usuario_no_puede_crear_usuarios(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::USUARIO, $org->id);

        $this->postJson('/api/usuarios', $this->payloadUsuario())
            ->assertStatus(403);
    }

    public function test_usuario_no_puede_consultar_catalogos(): void
    {
        $this->actuandoComoRol(Rol::USUARIO);

        $this->getJson('/api/roles')->assertStatus(403);
        $this->getJson('/api/organizaciones')->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // ADMINISTRADOR (rol 2): limitado a su organización y a rol USUARIO
    // ---------------------------------------------------------------

    public function test_admin_solo_lista_usuarios_normales_de_su_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $admin = $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        // En la organización A: 2 usuarios normales + 1 administrador adicional.
        $u1 = $this->crearUsuario(Rol::USUARIO, $orgA->id);
        $u2 = $this->crearUsuario(Rol::USUARIO, $orgA->id);
        $otroAdminA = $this->crearUsuario(Rol::ADMINISTRADOR, $orgA->id);

        // En la organización B: 1 usuario normal (no debe verse).
        $uB = $this->crearUsuario(Rol::USUARIO, $orgB->id);

        $resp = $this->getJson('/api/usuarios')->assertStatus(200);

        $ids = collect($resp->json())->pluck('id')->all();

        // Ve a los usuarios normales de su organización.
        $this->assertContains($u1->id, $ids);
        $this->assertContains($u2->id, $ids);
        // NO ve: el otro administrador de su org, ni el usuario de otra org,
        // ni a sí mismo (es administrador).
        $this->assertNotContains($otroAdminA->id, $ids);
        $this->assertNotContains($uB->id, $ids);
        $this->assertNotContains($admin->id, $ids);

        // La respuesta nunca expone la contraseña.
        foreach ($resp->json() as $fila) {
            $this->assertArrayNotHasKey('contrasena', $fila);
        }
    }

    public function test_admin_crea_usuario_forzando_su_organizacion_y_rol_usuario(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        // Intento de escalar: enviar rol SUPERADMIN y organización ajena.
        $payload = $this->payloadUsuario([
            'roles_id'          => Rol::SUPERADMIN,
            'organizaciones_id' => $orgB->id,
        ]);

        $resp = $this->postJson('/api/usuarios', $payload)->assertStatus(201);

        // El servidor debe ignorar el payload: rol USUARIO y la org del admin.
        $this->assertSame((int) Rol::USUARIO, (int) $resp->json('roles_id'));
        $this->assertSame((int) $orgA->id, (int) $resp->json('organizaciones_id'));

        $this->assertDatabaseHas('usuarios', [
            'correo'            => $payload['correo'],
            'roles_id'          => Rol::USUARIO,
            'organizaciones_id' => $orgA->id,
        ]);
    }

    public function test_admin_no_puede_modificar_usuario_de_otra_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);
        $usuarioB = $this->crearUsuario(Rol::USUARIO, $orgB->id);

        // 404 para no revelar existencia de recursos de otra organización.
        $this->putJson('/api/usuarios/'.$usuarioB->id, $this->payloadUsuario())
            ->assertStatus(404);
    }

    public function test_admin_no_puede_desactivar_usuario_de_otra_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);
        $usuarioB = $this->crearUsuario(Rol::USUARIO, $orgB->id);

        $this->deleteJson('/api/usuarios/'.$usuarioB->id)->assertStatus(404);

        // No debe haberse desactivado.
        $this->assertDatabaseHas('usuarios', [
            'id'     => $usuarioB->id,
            'estado' => true,
        ]);
    }

    public function test_admin_no_puede_modificar_cuenta_privilegiada_de_su_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $otroAdmin = $this->crearUsuario(Rol::ADMINISTRADOR, $orgA->id);

        $this->putJson('/api/usuarios/'.$otroAdmin->id, $this->payloadUsuario())
            ->assertStatus(403);
    }

    public function test_admin_no_puede_desactivar_cuenta_privilegiada_de_su_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $superadmin = $this->crearUsuario(Rol::SUPERADMIN, $orgA->id);

        $this->deleteJson('/api/usuarios/'.$superadmin->id)->assertStatus(403);

        $this->assertDatabaseHas('usuarios', [
            'id'     => $superadmin->id,
            'estado' => true,
        ]);
    }

    public function test_admin_puede_desactivar_usuario_normal_de_su_organizacion(): void
    {
        $orgA = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $usuario = $this->crearUsuario(Rol::USUARIO, $orgA->id);

        $this->deleteJson('/api/usuarios/'.$usuario->id)
            ->assertStatus(200)
            ->assertJson(['message' => 'Usuario eliminado correctamente.']);

        // Baja lógica: estado = false.
        $this->assertDatabaseHas('usuarios', [
            'id'     => $usuario->id,
            'estado' => false,
        ]);
    }

    public function test_admin_catalogo_roles_solo_devuelve_usuario(): void
    {
        $this->actuandoComoRol(Rol::ADMINISTRADOR);

        $resp = $this->getJson('/api/roles')->assertStatus(200);

        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertSame([(int) Rol::USUARIO], array_map('intval', $ids));
    }

    public function test_admin_catalogo_organizaciones_solo_la_propia(): void
    {
        $orgA = $this->crearOrganizacion();
        $this->crearOrganizacion(); // otra organización que NO debe aparecer
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        $resp = $this->getJson('/api/organizaciones')->assertStatus(200);

        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertSame([(int) $orgA->id], array_map('intval', $ids));
    }

    // ---------------------------------------------------------------
    // SUPERADMIN (rol 1): acceso global
    // ---------------------------------------------------------------

    public function test_superadmin_lista_usuarios_de_todas_las_organizaciones(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::SUPERADMIN, $orgA->id);

        $uA = $this->crearUsuario(Rol::USUARIO, $orgA->id);
        $uB = $this->crearUsuario(Rol::USUARIO, $orgB->id);
        $adminB = $this->crearUsuario(Rol::ADMINISTRADOR, $orgB->id);

        $resp = $this->getJson('/api/usuarios')->assertStatus(200);
        $ids = collect($resp->json())->pluck('id')->all();

        // Ve usuarios de cualquier organización y de cualquier rol.
        $this->assertContains($uA->id, $ids);
        $this->assertContains($uB->id, $ids);
        $this->assertContains($adminB->id, $ids);
    }

    public function test_superadmin_puede_crear_usuario_con_rol_y_organizacion_indicados(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();

        $this->actuandoComoRol(Rol::SUPERADMIN, $orgA->id);

        $payload = $this->payloadUsuario([
            'roles_id'          => Rol::ADMINISTRADOR,
            'organizaciones_id' => $orgB->id,
        ]);

        $resp = $this->postJson('/api/usuarios', $payload)->assertStatus(201);

        // El superadmin SÍ puede fijar rol y organización.
        $this->assertSame((int) Rol::ADMINISTRADOR, (int) $resp->json('roles_id'));
        $this->assertSame((int) $orgB->id, (int) $resp->json('organizaciones_id'));
    }

    public function test_superadmin_catalogo_roles_devuelve_todos(): void
    {
        $this->actuandoComoRol(Rol::SUPERADMIN);

        $resp = $this->getJson('/api/roles')->assertStatus(200);
        $this->assertCount(3, $resp->json());
    }
}
