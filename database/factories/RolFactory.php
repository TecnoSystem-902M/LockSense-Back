<?php

namespace Database\Factories;

use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Rol (uso exclusivo en pruebas).
 *
 * El modelo Rol no usa el trait HasFactory (no se modifica el código de
 * producción). Se vincula el modelo mediante $model, por lo que debe usarse
 * como RolFactory::new()->create([...]).
 *
 * @extends Factory<Rol>
 */
class RolFactory extends Factory
{
    protected $model = Rol::class;

    public function definition(): array
    {
        return [
            'nombre'      => fake()->unique()->word(),
            'descripcion' => fake()->sentence(),
        ];
    }

    /** Rol SUPERADMIN con id fijo = 1. */
    public function superadmin(): static
    {
        return $this->state(fn () => [
            'id'          => \App\Models\Rol::SUPERADMIN,
            'nombre'      => 'SUPERADMIN',
            'descripcion' => 'Administrador global de la plataforma LockSense',
        ]);
    }

    /** Rol ADMINISTRADOR con id fijo = 2. */
    public function administrador(): static
    {
        return $this->state(fn () => [
            'id'          => \App\Models\Rol::ADMINISTRADOR,
            'nombre'      => 'ADMINISTRADOR',
            'descripcion' => 'Administrador de una organización',
        ]);
    }

    /** Rol USUARIO con id fijo = 3. */
    public function usuario(): static
    {
        return $this->state(fn () => [
            'id'          => \App\Models\Rol::USUARIO,
            'nombre'      => 'USUARIO',
            'descripcion' => 'Usuario de un casillero',
        ]);
    }
}
