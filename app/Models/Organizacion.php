<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organizacion extends Model
{
    protected $table = 'organizaciones';

    protected $fillable = [
        'nombre',
        'tipo',
        'direccion',
        'colonia',
        'ciudad',
        'codigo_postal',
        'telefono',
        'estado',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relaciones
    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'organizaciones_id');
    }

    public function casilleros()
    {
        return $this->hasMany(Casillero::class, 'organizaciones_id');
    }
}