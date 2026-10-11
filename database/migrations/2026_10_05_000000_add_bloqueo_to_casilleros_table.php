<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 — Bloqueo de casilleros.
 *
 * Añade a la tabla EXISTENTE `casilleros` dos columnas para modelar el
 * bloqueo de forma independiente de la ocupación (que se deriva de
 * `asignaciones.estado = true`) y del campo `estado` de presentación:
 *
 *   - bloqueado        boolean NOT NULL DEFAULT false
 *   - motivo_bloqueo   varchar(20) NULL  (valores: ADMINISTRATIVO | MANTENIMIENTO)
 *
 * Tras crear las columnas, preserva el bloqueo de los casilleros que ya
 * estuvieran en estado 'Bloqueado' (campo legado): bloqueado = true y
 * motivo_bloqueo = 'MANTENIMIENTO'. Solo afecta a esos registros de la
 * tabla `casilleros`; no toca usuarios, organizaciones, asignaciones,
 * credenciales RFID ni eventos.
 *
 * NO crea ni recrea tablas, NO borra registros, NO modifica relaciones.
 * Es reversible: down() elimina únicamente las dos columnas agregadas.
 *
 * Pendiente de ejecutar en PostgreSQL (ver informe: pasos de aplicación segura).
 */
return new class extends Migration
{
    public function up(): void
    {
        // En el entorno de pruebas el esquema de `casilleros` (incluidas estas
        // columnas) se define en tests/database/migrations; esta migración
        // productiva no debe ejecutarse allí (la tabla aún no existe cuando
        // RefreshDatabase corre migrate:fresh sobre database/migrations).
        if (app()->environment('testing')) {
            return;
        }

        Schema::table('casilleros', function (Blueprint $table) {
            if (!Schema::hasColumn('casilleros', 'bloqueado')) {
                $table->boolean('bloqueado')->default(false)->after('estado');
            }
            if (!Schema::hasColumn('casilleros', 'motivo_bloqueo')) {
                // Nullable; los valores permitidos (ADMINISTRATIVO/MANTENIMIENTO)
                // se validan en la capa de aplicación (CasilleroController).
                $table->string('motivo_bloqueo', 20)->nullable()->after('bloqueado');
            }
        });

        // Preservar el bloqueo de los casilleros que ya estaban 'Bloqueado'
        // (estado legado). Se tratan como bloqueo por MANTENIMIENTO porque no
        // existe asignación asociada para un bloqueo administrativo.
        // UPDATE acotado EXCLUSIVAMENTE a filas de `casilleros` con ese estado.
        DB::table('casilleros')
            ->where('estado', 'Bloqueado')
            ->update([
                'bloqueado'      => true,
                'motivo_bloqueo' => 'MANTENIMIENTO',
            ]);
    }

    public function down(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        Schema::table('casilleros', function (Blueprint $table) {
            if (Schema::hasColumn('casilleros', 'motivo_bloqueo')) {
                $table->dropColumn('motivo_bloqueo');
            }
            if (Schema::hasColumn('casilleros', 'bloqueado')) {
                $table->dropColumn('bloqueado');
            }
        });
    }
};
