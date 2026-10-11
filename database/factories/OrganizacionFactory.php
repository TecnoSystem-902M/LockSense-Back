<?php

namespace Database\Factories;

use App\Models\Organizacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Organizacion (uso exclusivo en pruebas).
 *
 * El modelo no usa HasFactory; se vincula mediante $model.
 * Usar como OrganizacionFactory::new()->create([...]).
 *
 * @extends Factory<Organizacion>
 */
class OrganizacionFactory extends Factory
{
    protected $model = Organizacion::class;

    public function definition(): array
    {
        return [
            'nombre'    => fake()->company(),
            'tipo'      => 'Empresa',
            'direccion' => fake()->streetAddress(),
            'telefono'  => fake()->numerify('##########'),
            'correo'    => fake()->unique()->companyEmail(),
            'estado'    => true,
        ];
    }
}
