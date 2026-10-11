<?php

namespace Database\Factories;

use App\Models\Organizacion;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Factory para App\Models\Usuario (uso exclusivo en pruebas).
 *
 * El modelo Usuario no usa HasFactory (no se modifica el código de
 * producción). Se vincula mediante $model; usar como
 * UsuarioFactory::new()->create([...]).
 *
 * Columnas reales: identificador, nombre, apellido_paterno,
 * apellido_materno, correo, contrasena, telefono, estado,
 * organizaciones_id, roles_id.
 *
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'identificador'     => Str::uuid()->toString(),
            'nombre'            => fake()->firstName(),
            'apellido_paterno'  => fake()->lastName(),
            'apellido_materno'  => fake()->lastName(),
            'correo'            => fake()->unique()->safeEmail(),
            'contrasena'        => Hash::make('password'),
            'telefono'          => fake()->numerify('##########'),
            'estado'            => true,
            // Por defecto crea su propia organización y rol USUARIO si no se
            // especifican. Las pruebas normalmente pasarán IDs explícitos.
            'organizaciones_id' => fn () => OrganizacionFactory::new()->create()->id,
            'roles_id'          => Rol::USUARIO,
        ];
    }

    /** Estado: cuenta inactiva (estado = false). */
    public function inactivo(): static
    {
        return $this->state(fn () => ['estado' => false]);
    }

    /** Asigna el rol SUPERADMIN. */
    public function superadmin(): static
    {
        return $this->state(fn () => ['roles_id' => Rol::SUPERADMIN]);
    }

    /** Asigna el rol ADMINISTRADOR. */
    public function administrador(): static
    {
        return $this->state(fn () => ['roles_id' => Rol::ADMINISTRADOR]);
    }

    /** Asigna el rol USUARIO. */
    public function usuario(): static
    {
        return $this->state(fn () => ['roles_id' => Rol::USUARIO]);
    }

    /** Asigna una organización concreta. */
    public function paraOrganizacion(int $organizacionId): static
    {
        return $this->state(fn () => ['organizaciones_id' => $organizacionId]);
    }
}
