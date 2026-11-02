<?php

use App\Models\Organizacion;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\CodigoOrganizacion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Asigna código a las organizaciones que no tienen y agrega el prefijo
 * a las matrículas de sus usuarios (rol USUARIO). Se puede correr varias
 * veces: lo que ya tiene código o prefijo no se vuelve a tocar.
 *
 *   php artisan locksense:codigos-organizacion
 */
Artisan::command('locksense:codigos-organizacion', function () {
    DB::transaction(function () {
        foreach (Organizacion::query()->orderBy('id')->get() as $organizacion) {
            $codigo = CodigoOrganizacion::asegurar($organizacion);
            $this->info("{$organizacion->nombre} -> {$codigo}");

            $usuarios = Usuario::query()
                ->where('organizaciones_id', $organizacion->id)
                ->where('roles_id', Rol::USUARIO)
                ->whereNotNull('identificador')
                ->get();

            foreach ($usuarios as $usuario) {
                $nuevo = CodigoOrganizacion::componer($codigo, $usuario->identificador);

                if ($nuevo === $usuario->identificador) {
                    continue;
                }

                $repetido = Usuario::query()
                    ->whereRaw('UPPER(identificador) = ?', [strtoupper($nuevo)])
                    ->where('id', '!=', $usuario->id)
                    ->exists();

                if ($repetido) {
                    $this->warn("  Usuario {$usuario->id}: {$nuevo} ya existe, revisar a mano.");
                    continue;
                }

                $this->line("  Usuario {$usuario->id}: {$usuario->identificador} -> {$nuevo}");
                $usuario->forceFill(['identificador' => $nuevo])->save();
            }
        }
    });

    $this->info('Listo.');
})->purpose('Genera códigos de organización y prefija las matrículas de sus usuarios');