<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hace que la columna `usuarios.correo` admita valores NULL.
 *
 * Motivo: los usuarios dados de alta por una organización (rol USUARIO) no
 * necesitan correo electrónico; inician sesión con su `identificador`. El
 * registro público (rol ADMINISTRADOR) sigue usando correo.
 *
 * - Solo se altera la nulabilidad: se ejecuta `DROP NOT NULL`.
 * - Se CONSERVA la restricción UNIQUE existente sobre `correo`. En PostgreSQL
 *   varios valores NULL no colisionan en un índice UNIQUE, por lo que múltiples
 *   usuarios sin correo son válidos y los correos presentes siguen siendo únicos.
 * - NO se eliminan datos, NO se recrea la tabla, NO se tocan otras columnas.
 * - Reversible: down() restaura NOT NULL (ver nota de riesgo en el informe).
 *
 * En el entorno de pruebas el esquema de `usuarios` se define en
 * tests/database/migrations, por lo que esta migración productiva no se
 * ejecuta allí (la tabla aún no existe cuando RefreshDatabase corre
 * migrate:fresh sobre database/migrations).
 *
 * Pendiente de ejecutar en PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        DB::statement('ALTER TABLE usuarios ALTER COLUMN correo DROP NOT NULL');
    }

    public function down(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        // Restaura NOT NULL. Solo es seguro si no existen filas con correo NULL;
        // de lo contrario PostgreSQL rechazará el cambio (ver informe).
        DB::statement('ALTER TABLE usuarios ALTER COLUMN correo SET NOT NULL');
    }
};
