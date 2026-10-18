<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Acceso extends Model
{
    protected $table = 'accesos';

    const AUTORIZADO = 'autorizado';
    const DENEGADO   = 'denegado';

    protected $fillable = [
        'metodo',             // 'rfid' | 'facial'
        'resultado',          // 'autorizado' | 'denegado'
        'motivo',             // por que se denego
        'fecha_hora',
        'casilleros_id',
        'usuarios_id',
        'dispositivos_id',
        'codigo_leido',       // UID leido por el lector
        'fecha_apertura',
        'fecha_cierre',
        'duracion_segundos',
    ];

    protected $casts = [
        'fecha_hora'        => 'datetime',
        'fecha_apertura'    => 'datetime',
        'fecha_cierre'      => 'datetime',
        'duracion_segundos' => 'integer',
    ];

    // La tabla accesos no tiene created_at/updated_at.
    public $timestamps = false;

    public function casillero()
    {
        return $this->belongsTo(Casillero::class, 'casilleros_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuarios_id');
    }

    public function dispositivo()
    {
        return $this->belongsTo(Dispositivo::class, 'dispositivos_id');
    }
}