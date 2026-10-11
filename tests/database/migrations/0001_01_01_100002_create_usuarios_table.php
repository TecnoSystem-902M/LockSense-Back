<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración EXCLUSIVA DE PRUEBAS (no vive en database/migrations).
 * Reproduce el esquema mínimo de `usuarios` que usa el código real
 * (App\Models\Usuario y App\Models\UsuarioCrud).
 * Portátil a SQLite (:memory:). No se ejecuta contra PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizaciones_id');
            $table->foreignId('roles_id');
            $table->string('identificador', 100)->unique();
            $table->string('nombre', 100);
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100)->nullable();
            $table->string('correo', 150)->nullable()->unique();
            $table->string('contrasena', 255);
            $table->string('telefono', 20)->nullable();
            $table->boolean('estado')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
