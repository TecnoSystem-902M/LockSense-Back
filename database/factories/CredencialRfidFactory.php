<?php

namespace Database\Factories;

use App\Models\CredencialRfid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\CredencialRfid (uso exclusivo en pruebas).
 * El modelo no usa HasFactory; se vincula mediante $model.
 * Usar como CredencialRfidFactory::new()->create([...]).
 *
 * @extends Factory<CredencialRfid>
 */
class CredencialRfidFactory extends Factory
{
    protected $model = CredencialRfid::class;

    public function definition(): array
    {
        return [
            'usuarios_id'    => null,
            'uid'            => 'RFID-'.strtoupper(fake()->unique()->bothify('####??')),
            'tipo'           => 'tarjeta',
            'estado'         => true,
            'fecha_registro' => now(),
        ];
    }
}
