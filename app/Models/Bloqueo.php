<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bloqueo extends Model
{
    protected $table = 'bloqueos';

    protected $fillable = [
        'tipo',
        'motivo',
        'inicio',
        'fin',
        'activo',
        'casilleros_id',
        'dispositivos_id',
    ];

    protected $casts = [
        'inicio'     => 'datetime',
        'fin'        => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function casillero()
    {
        return $this->belongsTo(Casillero::class, 'casilleros_id');
    }

    public function dispositivo()
{
    return $this->belongsTo(Dispositivo::class, 'dispositivos_id');
}
}