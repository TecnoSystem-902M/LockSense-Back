<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración EXCLUSIVA DE PRUEBAS (no vive en database/migrations).
 * Reproduce el esquema mínimo de `eventos_casillero` del esquema real:
 * id, casilleros_id, tipo_evento, descripcion, fecha_hora.
 * No tiene created_at/updated_at.
 * Portátil a SQLite (:memory:). No se ejecuta contra PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_casillero', function (Blueprint $table) {
            $table->id();
            $table->foreignId('casilleros_id');
            $table->string('tipo_evento', 50)->nullable();
            $table->string('descripcion', 255)->nullable();
            $table->timestamp('fecha_hora')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_casillero');
    }
};
