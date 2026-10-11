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
        'telefono',
        'correo',
        'estado',
    ];

    protected $casts = [
        'estado'     => 'boolean',
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