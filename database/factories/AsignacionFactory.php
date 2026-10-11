<?php

namespace Database\Factories;

use App\Models\Asignacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Asignacion (uso exclusivo en pruebas).
 * El modelo no usa HasFactory; se vincula mediante $model.
 * Usar como AsignacionFactory::new()->create([...]).
 *
 * @extends Factory<Asignacion>
 */
class AsignacionFactory extends Factory
{
    protected $model = Asignacion::class;

    public function definition(): array
    {
        return [
            'usuarios_id'      => null,
            'casilleros_id'    => null,
            'fecha_asignacion' => now(),
            'fecha_liberacion' => null,
            'estado'           => true, // activa por defecto
        ];
    }

    /** Asignación liberada (inactiva). */
    public function liberada(): static
    {
        return $this->state(fn () => [
            'estado'           => false,
            'fecha_liberacion' => now(),
        ]);
    }
}
