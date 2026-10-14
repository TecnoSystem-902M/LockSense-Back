<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas de autenticación de las rutas privadas de la API.
 *
 * Se usa RefreshDatabase para que las migraciones (incluida
 * personal_access_tokens, que Sanctum consulta al validar un token)
 * se ejecuten sobre la base SQLite en memoria antes de cada prueba.
 * Las migraciones del proyecto son las estándar de Laravel y son
 * compatibles con SQLite.
 *
 * La configuración de phpunit.xml fuerza APP_ENV=testing con SQLite en
 * memoria (DB_CONNECTION=sqlite, DB_DATABASE=:memory:), por lo que estas
 * pruebas no se conectan a la base PostgreSQL `locksense`.
 */
class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rutas privadas (GET) que deben exigir autenticación.
     *
     * @return array<string, array{0:string}>
     */
    public static function rutasPrivadas(): array
    {
        return [
            'usuarios'          => ['/api/usuarios'],
            'roles'             => ['/api/roles'],
            'organizaciones'    => ['/api/organizaciones'],
            'dashboard metrics' => ['/api/dashboard/metrics'],
        ];
    }

    /**
     * Confirma que la ejecución NO usa la conexión PostgreSQL `locksense`.
     */
    public function test_el_entorno_de_pruebas_usa_sqlite_en_memoria(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    /**
     * Sin token: debe responder 401 JSON, sin header Accept.
     *
     * @dataProvider rutasPrivadas
     */
    public function test_sin_token_sin_accept_devuelve_401_json(string $ruta): void
    {
        // get() no envía Accept: application/json; este era el caso que antes
        // provocaba un 500 por "Route [login] not defined".
        $response = $this->get($ruta);

        $response->assertStatus(401);
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertJson($response->getContent());
    }

    /**
     * Sin token: debe responder 401 JSON, con header Accept: application/json.
     *
     * @dataProvider rutasPrivadas
     */
    public function test_sin_token_con_accept_json_devuelve_401_json(string $ruta): void
    {
        $response = $this->getJson($ruta);

        $response->assertStatus(401);
        $response->assertHeader('content-type', 'application/json');
    }

    /**
     * Token inválido: debe responder 401 JSON (sin header Accept).
     *
     * @dataProvider rutasPrivadas
     */
    public function test_token_invalido_sin_accept_devuelve_401_json(string $ruta): void
    {
        $response = $this->get($ruta, [
            'Authorization' => 'Bearer token-invalido',
        ]);

        $response->assertStatus(401);
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertJson($response->getContent());
    }

    /**
     * Token inválido: debe responder 401 JSON (con header Accept).
     *
     * @dataProvider rutasPrivadas
     */
    public function test_token_invalido_con_accept_json_devuelve_401_json(string $ruta): void
    {
        $response = $this->getJson($ruta, [
            'Authorization' => 'Bearer token-invalido',
        ]);

        $response->assertStatus(401);
        $response->assertHeader('content-type', 'application/json');
    }
}
