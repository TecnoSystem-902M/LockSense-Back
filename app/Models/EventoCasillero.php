<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoCasillero extends Model
{
    protected $table = 'eventos_casillero';

    protected $fillable = [
        'casilleros_id',
        'tipo_evento',
        'descripcion',
        'fecha_hora',
    ];

    protected $casts = [
        'fecha_hora' => 'datetime',
    ];

    // El esquema real no tiene created_at/updated_at.
    public $timestamps = false;

    public function casillero()
    {
        return $this->belongsTo(Casillero::class, 'casilleros_id');
    }
}
