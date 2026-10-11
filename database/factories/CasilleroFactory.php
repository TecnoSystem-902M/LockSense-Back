<?php

namespace Database\Factories;

use App\Models\Casillero;
use App\Models\Organizacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Casillero (uso exclusivo en pruebas).
 * El modelo no usa HasFactory; se vincula mediante $model.
 * Usar como CasilleroFactory::new()->create([...]).
 *
 * @extends Factory<Casillero>
 */
class CasilleroFactory extends Factory
{
    protected $model = Casillero::class;

    public function definition(): array
    {
        return [
            'organizaciones_id' => fn () => OrganizacionFactory::new()->create()->id,
            'numero'            => 'C-'.fake()->unique()->numberBetween(1, 100000),
            'estado'            => 'Disponible',
            'ubicacion'         => 'Zona '.fake()->randomLetter(),
        ];
    }

    public function bloqueado(): static
    {
        return $this->state(fn () => ['estado' => 'Bloqueado']);
    }

    /** Casillero con bloqueo persistente (columnas de Etapa 2). */
    public function bloqueadoPor(string $motivo): static
    {
        return $this->state(fn () => [
            'bloqueado'      => true,
            'motivo_bloqueo' => $motivo,
        ]);
    }

    public function asignado(): static
    {
        return $this->state(fn () => ['estado' => 'Asignado']);
    }

    public function paraOrganizacion(int $organizacionId): static
    {
        return $this->state(fn () => ['organizaciones_id' => $organizacionId]);
    }
}
