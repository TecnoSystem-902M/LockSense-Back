<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración EXCLUSIVA DE PRUEBAS (no vive en database/migrations).
 * Esquema mínimo de `casilleros` necesario para las futuras pruebas de
 * métricas del Dashboard (DashboardController usa DB::table('casilleros')
 * con where('estado','Bloqueado') y where('organizaciones_id', ...)).
 *
 * `estado` se modela como string porque en la BD real es varchar y el
 * código compara contra el literal 'Bloqueado'.
 * Portátil a SQLite (:memory:). No se ejecuta contra PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casilleros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizaciones_id');
            $table->string('numero', 50)->nullable();
            $table->string('ubicacion', 150)->nullable();
            $table->string('estado', 20)->nullable();
            // Etapa 2: bloqueo independiente de la ocupación.
            $table->boolean('bloqueado')->default(false);
            $table->string('motivo_bloqueo', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casilleros');
    }
};
