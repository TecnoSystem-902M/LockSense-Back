<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asignacion extends Model
{
    protected $table = 'asignaciones';

    protected $fillable = [
        'usuarios_id',
        'casilleros_id',
        'fecha_asignacion',
        'fecha_liberacion',
        'estado',
    ];

    protected $casts = [
        'fecha_asignacion' => 'datetime',
        'fecha_liberacion' => 'datetime',
        // ❌ NO castear 'estado' a boolean.
        // La columna es character(1): 'A' = activa, 'I' = inactiva.
    ];

    // La tabla asignaciones no tiene created_at/updated_at en el esquema real.
    public $timestamps = false;

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuarios_id');
    }

    public function casillero()
    {
        return $this->belongsTo(Casillero::class, 'casilleros_id');
    }
}