<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración EXCLUSIVA DE PRUEBAS (no vive en database/migrations).
 * Reproduce el esquema mínimo de `credenciales_rfid` del esquema real:
 * id, usuarios_id, uid, tipo, estado (boolean), fecha_registro.
 * No tiene created_at/updated_at.
 * Portátil a SQLite (:memory:). No se ejecuta contra PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciales_rfid', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuarios_id');
            $table->string('uid', 100);
            $table->string('tipo', 50)->nullable();
            $table->boolean('estado')->nullable();
            $table->timestamp('fecha_registro')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciales_rfid');
    }
};
