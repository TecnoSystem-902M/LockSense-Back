<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración EXCLUSIVA DE PRUEBAS (no vive en database/migrations).
 * Reproduce el esquema mínimo de `asignaciones` del esquema real:
 * id, usuarios_id, casilleros_id, fecha_asignacion, fecha_liberacion,
 * estado (boolean). No tiene created_at/updated_at.
 * Portátil a SQLite (:memory:). No se ejecuta contra PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuarios_id');
            $table->foreignId('casilleros_id');
            $table->timestamp('fecha_asignacion')->nullable();
            $table->timestamp('fecha_liberacion')->nullable();
            $table->boolean('estado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones');
    }
};
