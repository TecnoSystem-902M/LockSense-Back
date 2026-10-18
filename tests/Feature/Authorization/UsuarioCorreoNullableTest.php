<?php

namespace Tests\Feature\Authorization;

use App\Models\Rol;
use App\Models\UsuarioCrud;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Pruebas de las correcciones de usuarios:
 *  - Un ADMINISTRADOR crea usuarios SIN correo (se guarda NULL), con rol
 *    USUARIO y organización forzados en el servidor.
 *  - El ADMINISTRADOR no puede forzar organización ni rol desde el payload.
 *  - Login por identificador para usuarios de organización (sin correo) y
 *    login por correo para el flujo público.
 *
 * Se ejecutan sobre SQLite en memoria (ver AuthorizationTestCase).
 */
class UsuarioCorreoNullableTest extends AuthorizationTestCase
{
    private function payloadBase(array $overrides = []): array
    {
        return array_merge([
            'nombre'           => 'Nombre',
            'apellido_paterno' => 'Apellido',
            'apellido_materno' => 'Materno',
            'identificador'    => Str::uuid()->toString(),
            'contrasena'       => 'password123',
            'telefono'         => '1234567890',
        ], $overrides);
    }

    public function test_admin_crea_usuario_sin_correo_queda_null(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        // Sin enviar 'correo'.
        $resp = $this->postJson('/api/usuarios', $this->payloadBase())
            ->assertStatus(201);

        $this->assertNull($resp->json('correo'));
        $this->assertSame($org->id, $resp->json('organizaciones_id'));
        $this->assertSame(Rol::USUARIO, $resp->json('roles_id'));

        $this->assertDatabaseHas('usuarios', [
            'identificador' => $resp->json('identificador'),
            'correo'        => null,
            'roles_id'      => Rol::USUARIO,
        ]);
    }

    public function test_pueden_coexistir_varios_usuarios_sin_correo(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $this->postJson('/api/usuarios', $this->payloadBase())->assertStatus(201);
        // Un segundo usuario sin correo no debe colisionar (NULL no es único).
        $this->postJson('/api/usuarios', $this->payloadBase())->assertStatus(201);

        $this->assertSame(2, UsuarioCrud::query()
            ->where('organizaciones_id', $org->id)
            ->whereNull('correo')
            ->count());
    }

    public function test_admin_no_puede_forzar_organizacion_ni_rol(): void
    {
        $orgA = $this->crearOrganizacion();
        $orgB = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $orgA->id);

        // Intenta inyectar otra organización y rol privilegiado.
        $resp = $this->postJson('/api/usuarios', $this->payloadBase([
            'organizaciones_id' => $orgB->id,
            'roles_id'          => Rol::ADMINISTRADOR,
        ]))->assertStatus(201);

        // El servidor ignora lo enviado: su propia organización + rol USUARIO.
        $this->assertSame($orgA->id, $resp->json('organizaciones_id'));
        $this->assertSame(Rol::USUARIO, $resp->json('roles_id'));
    }

    public function test_admin_crea_usuario_con_correo_opcional(): void
    {
        $org = $this->crearOrganizacion();
        $this->actuandoComoRol(Rol::ADMINISTRADOR, $org->id);

        $correo = 'opt_'.Str::random(6).'@ejemplo.com';
        $resp = $this->postJson('/api/usuarios', $this->payloadBase([
            'correo' => $correo,
        ]))->assertStatus(201);

        $this->assertSame($correo, $resp->json('correo'));
    }

    public function test_login_por_identificador_para_usuario_de_organizacion(): void
    {
        $org = $this->crearOrganizacion();

        $identificador = Str::uuid()->toString();
        UsuarioCrud::query()->create([
            'organizaciones_id' => $org->id,
            'roles_id'          => Rol::USUARIO,
            'identificador'     => $identificador,
            'nombre'            => 'Sin',
            'apellido_paterno'  => 'Correo',
            'apellido_materno'  => null,
            'correo'            => null,
            'contrasena'        => Hash::make('password123'),
            'telefono'          => null,
            'estado'            => true,
        ]);

        // Login por identificador (sin correo) debe funcionar.
        $this->postJson('/api/login', [
            'identificador' => $identificador,
            'password'      => 'password123',
        ])->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }

    public function test_login_por_correo_sigue_funcionando(): void
    {
        $org = $this->crearOrganizacion();

        $correo = 'login_'.Str::random(6).'@ejemplo.com';
        UsuarioCrud::query()->create([
            'organizaciones_id' => $org->id,
            'roles_id'          => Rol::ADMINISTRADOR,
            'identificador'     => Str::uuid()->toString(),
            'nombre'            => 'Con',
            'apellido_paterno'  => 'Correo',
            'apellido_materno'  => null,
            'correo'            => $correo,
            'contrasena'        => Hash::make('password123'),
            'telefono'          => null,
            'estado'            => true,
        ]);

        $this->postJson('/api/login', [
            'email'    => $correo,
            'password' => 'password123',
        ])->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }

    public function test_login_rechaza_credenciales_invalidas_por_identificador(): void
    {
        $this->postJson('/api/login', [
            'identificador' => Str::uuid()->toString(),
            'password'      => 'loquesea123',
        ])->assertStatus(401);
    }
}
