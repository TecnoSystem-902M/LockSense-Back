<?php

namespace Tests\Feature\Authorization;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEST DE HUMO TEMPORAL — se elimina tras validar la etapa.
 *
 * Verifica que la clase base AuthorizationTestCase prepara correctamente
 * el esquema de dominio en SQLite en memoria y que las factories/Sanctum
 * funcionan. No prueba reglas de autorización (eso es una etapa posterior).
 */
class SchemaSmokeTest extends AuthorizationTestCase
{
    public function test_entorno_es_sqlite_en_memoria(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_tablas_de_dominio_existen(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('organizaciones'));
        $this->assertTrue(Schema::hasTable('usuarios'));
        $this->assertTrue(Schema::hasTable('casilleros'));
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
    }

    public function test_roles_sembrados(): void
    {
        $this->assertSame(3, Rol::query()->count());
        $this->assertSame('SUPERADMIN', Rol::query()->find(Rol::SUPERADMIN)->nombre);
        $this->assertSame('ADMINISTRADOR', Rol::query()->find(Rol::ADMINISTRADOR)->nombre);
        $this->assertSame('USUARIO', Rol::query()->find(Rol::USUARIO)->nombre);
    }

    public function test_helpers_crean_usuario_y_organizacion(): void
    {
        $org = $this->crearOrganizacion(['nombre' => 'Org Prueba']);
        $usuario = $this->crearUsuario(Rol::ADMINISTRADOR, $org->id);

        $this->assertDatabaseHas('organizaciones', ['nombre' => 'Org Prueba']);
        $this->assertDatabaseHas('usuarios', [
            'id'                => $usuario->id,
            'roles_id'          => Rol::ADMINISTRADOR,
            'organizaciones_id' => $org->id,
            'estado'            => true,
        ]);
        // La contraseña nunca debe exponerse al serializar el modelo.
        $this->assertArrayNotHasKey('contrasena', $usuario->toArray());
    }

    public function test_sanctum_acting_as_autentica_a_usuario(): void
    {
        $usuario = $this->actuandoComoRol(Rol::SUPERADMIN);

        $this->assertInstanceOf(Usuario::class, $usuario);
        $this->assertSame((int) Rol::SUPERADMIN, (int) $usuario->roles_id);
    }
}
