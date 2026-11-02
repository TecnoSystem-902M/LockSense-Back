<?php

namespace Tests\Feature;

use App\Models\Rostro;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pruebas del endpoint POST /api/rostro (registro facial, parte backend).
 *
 * AUTOCONTENIDA: crea en SQLite en memoria solo `usuarios` y `rostros`
 * (nombres/tipos del backup) y NO usa las migraciones compartidas de
 * tests/database/migrations (esquema anterior). El servicio Python se
 * simula con Http::fake(); nunca se llama al servicio real. phpunit.xml
 * fuerza sqlite :memory:, por lo que no se toca PostgreSQL.
 */
class RostroRegistroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        Schema::create('rostros', function (Blueprint $table) {
            $table->id();
            $table->text('embedding')->nullable();
            $table->string('modelo', 100)->nullable();
            $table->string('estado', 1)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('usuarios_id');
        });

        // Por defecto, el servicio facial está configurado (se simula con fake).
        Config::set('services.face.url', 'http://face-service.test');
        Config::set('services.face.key', 'test-key');
        Config::set('services.face.timeout', 5);
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

    private function autenticar(): Usuario
    {
        $usuario = $this->crearUsuario();
        Sanctum::actingAs($usuario, [], 'sanctum');

        return $usuario;
    }

    private function embeddingValido(): array
    {
        return array_fill(0, 512, 0.123);
    }

    /**
     * Imagen de prueba sin depender de la extensión GD. Se crea un archivo
     * con MIME image/jpeg; suficiente para las reglas file/image/mimes.
     */
    private function imagenFake(): UploadedFile
    {
        return UploadedFile::fake()->create('rostro.jpg', 10, 'image/jpeg');
    }

    public function test_sin_autenticacion_devuelve_401(): void
    {
        $this->postJson('/api/rostro')->assertStatus(401);
    }

    public function test_sin_imagen_devuelve_422(): void
    {
        $this->autenticar();

        $this->postJson('/api/rostro', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'imagen_invalida');
    }

    public function test_servicio_no_configurado_devuelve_503_y_no_persiste(): void
    {
        $this->autenticar();
        Config::set('services.face.url', null);
        Config::set('services.face.key', null);

        Http::fake();

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'servicio_no_configurado');

        $this->assertSame(0, Rostro::query()->count());
        Http::assertNothingSent();
    }

    public function test_embedding_valido_persiste_y_devuelve_201(): void
    {
        $usuario = $this->autenticar();

        Http::fake([
            '*/v1/embed' => Http::response([
                'ok'        => true,
                'embedding' => $this->embeddingValido(),
                'modelo'    => 'insightface-buffalo_l',
            ], 200),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tiene_rostro', true);

        $this->assertSame(1, Rostro::query()->count());
        $rostro = Rostro::query()->first();
        $this->assertSame('A', $rostro->estado);
        $this->assertSame($usuario->id, (int) $rostro->usuarios_id);
        $this->assertSame('insightface-buffalo_l', $rostro->modelo);
        $this->assertCount(512, json_decode($rostro->embedding, true));
    }

    public function test_servicio_responde_no_face_devuelve_422_y_no_persiste(): void
    {
        $this->autenticar();

        Http::fake([
            '*/v1/embed' => Http::response(['ok' => false, 'code' => 'no_face'], 422),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_se_detecto_rostro');

        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_servicio_responde_invalid_image_devuelve_422_y_no_persiste(): void
    {
        $this->autenticar();

        Http::fake([
            '*/v1/embed' => Http::response(['ok' => false, 'code' => 'invalid_image'], 422),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'imagen_invalida');

        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_servicio_responde_multiple_faces_devuelve_422_y_no_persiste(): void
    {
        $this->autenticar();

        Http::fake([
            '*/v1/embed' => Http::response(['ok' => false, 'code' => 'multiple_faces'], 422),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'mas_de_un_rostro');

        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_servicio_responde_low_quality_devuelve_422_y_no_persiste(): void
    {
        $this->autenticar();

        Http::fake([
            '*/v1/embed' => Http::response(['ok' => false, 'code' => 'low_quality'], 422),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'calidad_insuficiente');

        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_servicio_401_unauthorized_devuelve_503_y_no_persiste(): void
    {
        $this->autenticar();

        // Python rechaza la API key entre servicios con 401.
        Http::fake([
            '*/v1/embed' => Http::response(['ok' => false, 'code' => 'unauthorized'], 401),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'servicio_no_disponible');

        // No se devuelve 401 al frontend ni se persiste nada.
        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_servicio_503_unauthorized_devuelve_503_y_no_persiste(): void
    {
        $this->autenticar();

        // Python sin API key configurada responde 503 con code unauthorized.
        Http::fake([
            '*/v1/embed' => Http::response(['ok' => false, 'code' => 'unauthorized'], 503),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'servicio_no_disponible');

        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_embedding_dimension_incorrecta_no_persiste(): void
    {
        $this->autenticar();

        Http::fake([
            '*/v1/embed' => Http::response([
                'ok'        => true,
                'embedding' => array_fill(0, 128, 0.5), // dimensión incorrecta
                'modelo'    => 'insightface-buffalo_l',
            ], 200),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'respuesta_invalida');

        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_usuario_con_rostro_activo_recibe_409(): void
    {
        $usuario = $this->autenticar();

        Rostro::query()->create([
            'embedding'   => json_encode($this->embeddingValido()),
            'modelo'      => 'insightface-buffalo_l',
            'estado'      => 'A',
            'usuarios_id' => $usuario->id,
        ]);

        Http::fake();

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'rostro_ya_registrado');

        // No se contactó al servicio ni se creó un segundo registro.
        Http::assertNothingSent();
        $this->assertSame(1, Rostro::query()->count());
    }

    /**
     * Embedding de 512 componentes donde el primero es un valor no finito
     * ($noFinito). El resto son decimales válidos.
     *
     * @return array<int, float>
     */
    private function embeddingConValor(float $noFinito): array
    {
        $embedding = array_fill(0, 512, 0.123);
        $embedding[0] = $noFinito;

        return $embedding;
    }

    private function simularServicioConEmbedding(array $embedding): void
    {
        Http::fake([
            '*/v1/embed' => Http::response([
                'ok'        => true,
                'embedding' => $embedding,
                'modelo'    => 'insightface-buffalo_l',
            ], 200),
        ]);
    }

    /**
     * Invoca el método privado embeddingValido() del controlador por reflexión.
     * Esto prueba DIRECTAMENTE la lógica de validación (incluido is_finite),
     * sin depender de la serialización HTTP: NAN/INF no sobreviven a JSON, por
     * lo que una prueba de extremo a extremo no ejercitaría esa rama.
     */
    private function invocarEmbeddingValido(array $datos): bool
    {
        $controller = new \App\Http\Controllers\RostroController();
        $metodo = new \ReflectionMethod($controller, 'embeddingValido');
        $metodo->setAccessible(true);

        return $metodo->invoke($controller, $datos);
    }

    private function respuestaConEmbedding(array $embedding): array
    {
        return ['ok' => true, 'embedding' => $embedding, 'modelo' => 'insightface-buffalo_l'];
    }

    public function test_embeddingValido_rechaza_nan(): void
    {
        $this->assertFalse(
            $this->invocarEmbeddingValido($this->respuestaConEmbedding($this->embeddingConValor(NAN)))
        );
    }

    public function test_embeddingValido_rechaza_inf(): void
    {
        $this->assertFalse(
            $this->invocarEmbeddingValido($this->respuestaConEmbedding($this->embeddingConValor(INF)))
        );
    }

    public function test_embeddingValido_rechaza_inf_negativo(): void
    {
        $this->assertFalse(
            $this->invocarEmbeddingValido($this->respuestaConEmbedding($this->embeddingConValor(-INF)))
        );
    }

    public function test_embeddingValido_acepta_512_finitos(): void
    {
        $this->assertTrue(
            $this->invocarEmbeddingValido($this->respuestaConEmbedding(array_fill(0, 512, 0.123)))
        );
    }

    public function test_embeddingValido_rechaza_valor_no_numerico(): void
    {
        $embedding = array_fill(0, 512, 0.123);
        $embedding[5] = 'no-numerico';

        $this->assertFalse(
            $this->invocarEmbeddingValido($this->respuestaConEmbedding($embedding))
        );
    }

    /**
     * Extremo a extremo: si el servicio enviara valores no finitos, el cuerpo
     * no es JSON serializable (NAN/INF rompen json_encode y el cuerpo llega
     * vacío), de modo que la respuesta se rechaza igualmente sin persistir.
     * No prueba is_finite() (lo hacen los tests por reflexión de arriba), pero
     * confirma que el flujo completo nunca guarda en ese escenario.
     */
    public function test_embedding_no_finito_no_persiste_extremo_a_extremo(): void
    {
        $this->autenticar();
        $this->simularServicioConEmbedding($this->embeddingConValor(NAN));

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'respuesta_invalida');

        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_ok_falso_con_embedding_valido_no_persiste(): void
    {
        $this->autenticar();

        Http::fake([
            '*/v1/embed' => Http::response([
                'ok'        => false,
                'embedding' => $this->embeddingValido(),
                'modelo'    => 'insightface-buffalo_l',
            ], 200),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'respuesta_invalida');

        $this->assertSame(0, Rostro::query()->count());
    }

    public function test_ok_ausente_con_embedding_valido_no_persiste(): void
    {
        $this->autenticar();

        // Respuesta 200 con embedding válido pero SIN la clave `ok`.
        Http::fake([
            '*/v1/embed' => Http::response([
                'embedding' => $this->embeddingValido(),
                'modelo'    => 'insightface-buffalo_l',
            ], 200),
        ]);

        $this->postJson('/api/rostro', [
            'imagen' => $this->imagenFake(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'respuesta_invalida');

        $this->assertSame(0, Rostro::query()->count());
    }
}
