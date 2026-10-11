<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración EXCLUSIVA DE PRUEBAS (no vive en database/migrations).
 * Reproduce el esquema mínimo de `roles` que usa el código real.
 * Portátil a SQLite (:memory:). No se ejecuta contra PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50);
            $table->string('descripcion', 255)->nullable();
            // El modelo App\Models\Rol declara $timestamps = false.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
