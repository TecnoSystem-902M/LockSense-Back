<?php

namespace Tests\Feature\Authorization;

use App\Models\Organizacion;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Factories\OrganizacionFactory;
use Database\Factories\UsuarioFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Clase base EXCLUSIVA para las futuras pruebas de autorización por roles.
 *
 * - Usa RefreshDatabase sobre SQLite en memoria (según phpunit.xml).
 * - Carga las migraciones de dominio desde la carpeta exclusiva de pruebas
 *   `tests/database/migrations/` mediante loadMigrationsFrom(), sin añadirlas
 *   al directorio normal `database/migrations/`.
 * - Siembra los tres roles reales (1/2/3) en setUp.
 * - Ofrece helpers para crear organizaciones/usuarios y autenticar con
 *   Sanctum::actingAs() usando el modelo App\Models\Usuario.
 *
 * No contiene pruebas; es solo infraestructura. Las pruebas concretas
 * (UsuarioAuthorizationTest, DashboardMetricsAuthorizationTest) se
 * implementarán en una etapa posterior.
 */
abstract class AuthorizationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrarRoles();
    }

    /**
     * Hook de RefreshDatabase que se ejecuta DESPUÉS de preparar la base
     * (migrate:fresh) sobre SQLite en memoria. Aquí cargamos las migraciones
     * de dominio exclusivas de pruebas, ubicadas fuera de database/migrations.
     * Así las tablas base ya existen y las de dominio se crean a continuación.
     */
    protected function afterRefreshingDatabase(): void
    {
        $this->artisan('migrate', [
            '--path'     => 'tests/database/migrations',
            '--realpath' => false,
        ]);
    }

    /**
     * Inserta los tres roles reales con sus IDs fijos.
     */
    protected function sembrarRoles(): void
    {
        $roles = [
            Rol::SUPERADMIN    => ['nombre' => 'SUPERADMIN',    'descripcion' => 'Administrador global de la plataforma LockSense'],
            Rol::ADMINISTRADOR => ['nombre' => 'ADMINISTRADOR', 'descripcion' => 'Administrador de una organización'],
            Rol::USUARIO       => ['nombre' => 'USUARIO',       'descripcion' => 'Usuario de un casillero'],
        ];

        foreach ($roles as $id => $datos) {
            Rol::query()->create(['id' => $id] + $datos);
        }
    }

    /**
     * Crea una organización de prueba y devuelve su instancia.
     */
    protected function crearOrganizacion(array $overrides = []): Organizacion
    {
        return OrganizacionFactory::new()->create($overrides);
    }

    /**
     * Crea un usuario de prueba con rol y organización dados.
     *
     * @param int      $rolId         Rol::SUPERADMIN | ADMINISTRADOR | USUARIO
     * @param int|null $organizacionId Si es null, se crea una organización nueva.
     */
    protected function crearUsuario(int $rolId, ?int $organizacionId = null, array $overrides = []): Usuario
    {
        $organizacionId ??= $this->crearOrganizacion()->id;

        return UsuarioFactory::new()->create(array_merge([
            'roles_id'          => $rolId,
            'organizaciones_id' => $organizacionId,
            'estado'            => true,
        ], $overrides));
    }

    /**
     * Autentica como el usuario dado usando el guard de Sanctum.
     */
    protected function actuandoComo(Usuario $usuario): static
    {
        Sanctum::actingAs($usuario, [], 'sanctum');

        return $this;
    }

    /**
     * Atajo: crea un usuario del rol indicado y autentica como él.
     */
    protected function actuandoComoRol(int $rolId, ?int $organizacionId = null, array $overrides = []): Usuario
    {
        $usuario = $this->crearUsuario($rolId, $organizacionId, $overrides);
        $this->actuandoComo($usuario);

        return $usuario;
    }
}
