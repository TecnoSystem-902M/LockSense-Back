<?php

namespace Tests\Feature;

use App\Models\Rostro;
use App\Models\Usuario;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pruebas de la relación Usuario->rostro y del cálculo del indicador
 * "tiene_rostro" (rostroActivo) que login y me devuelven.
 *
 * Esta prueba es AUTOCONTENIDA: construye en SQLite en memoria solo las
 * tablas que necesita (`usuarios` y `rostros`), reproduciendo los nombres y
 * tipos de columna del esquema de referencia (backup.sql) para `rostros`:
 *   embedding text · modelo varchar(100) · estado char(1) · usuarios_id fk.
 *
 * No usa las migraciones compartidas de tests/database/migrations (que
 * reflejan un esquema anterior) para no depender de su estado. No toca
 * PostgreSQL: phpunit.xml fuerza sqlite :memory:.
 */
class RostroRelacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tabla mínima de usuarios suficiente para crear el modelo Usuario.
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('identificador', 100)->nullable();
            $table->string('nombre', 100)->nullable();
            $table->string('apellido_pa', 100)->nullable();
            $table->string('apellido_ma', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('password', 255)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('estado', 1)->nullable();
            $table->unsignedBigInteger('organizaciones_id')->nullable();
            $table->unsignedBigInteger('roles_id')->nullable();
            $table->timestamps();
        });

        // Tabla `rostros` con los nombres/tipos del backup del equipo.
        Schema::create('rostros', function (Blueprint $table) {
            $table->id();
            $table->text('embedding')->nullable();
            $table->string('modelo', 100)->nullable();
            $table->string('estado', 1)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('usuarios_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('rostros');
        Schema::dropIfExists('usuarios');

        parent::tearDown();
    }

    private function crearUsuario(): Usuario
    {
        return Usuario::query()->create([
            'identificador'     => Str::uuid()->toString(),
            'nombre'            => 'Prueba',
            'apellido_pa'       => 'Rostro',
            'email'             => Str::random(8) . '@ejemplo.com',
            'password'          => 'hash-no-relevante',
            'estado'            => 'A',
            'organizaciones_id' => 1,
            'roles_id'          => 3,
        ]);
    }

    public function test_usuario_con_rostro_activo_tiene_rostro_true(): void
    {
        $usuario = $this->crearUsuario();

        Rostro::query()->create([
            'embedding'   => json_encode(array_fill(0, 512, 0.1)),
            'modelo'      => 'insightface-test',
            'estado'      => 'A',
            'usuarios_id' => $usuario->id,
        ]);

        $this->assertTrue($usuario->rostroActivo()->exists());
    }

    public function test_usuario_sin_rostro_tiene_rostro_false(): void
    {
        $usuario = $this->crearUsuario();

        $this->assertFalse($usuario->rostroActivo()->exists());
    }

    public function test_usuario_con_rostro_inactivo_tiene_rostro_false(): void
    {
        $usuario = $this->crearUsuario();

        // Solo un rostro inactivo ('I'): no debe contar como activo.
        Rostro::query()->create([
            'embedding'   => json_encode(array_fill(0, 512, 0.2)),
            'modelo'      => 'insightface-test',
            'estado'      => 'I',
            'usuarios_id' => $usuario->id,
        ]);

        $this->assertFalse($usuario->rostroActivo()->exists());
    }

    public function test_varios_rostros_con_al_menos_uno_activo_tiene_rostro_true(): void
    {
        $usuario = $this->crearUsuario();

        // La tabla no tiene UNIQUE sobre usuarios_id: pueden coexistir varios.
        Rostro::query()->create([
            'embedding'   => json_encode(array_fill(0, 512, 0.3)),
            'modelo'      => 'insightface-test',
            'estado'      => 'I',
            'usuarios_id' => $usuario->id,
        ]);
        Rostro::query()->create([
            'embedding'   => json_encode(array_fill(0, 512, 0.4)),
            'modelo'      => 'insightface-test',
            'estado'      => 'A',
            'usuarios_id' => $usuario->id,
        ]);

        $this->assertTrue($usuario->rostroActivo()->exists());
        $this->assertSame(2, $usuario->rostros()->count());
    }
}
