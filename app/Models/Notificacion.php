<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    protected $table = 'notificaciones';

    const NO_LEIDA = 'N';
    const LEIDA    = 'S';

    // Tipos de notificacion de seguridad
    const TIPO_INTENTOS_FALLIDOS      = 'INTENTOS_FALLIDOS';
    const TIPO_APERTURA_NO_AUTORIZADA = 'APERTURA_NO_AUTORIZADA';

    protected $fillable = [
        'tipo',
        'mensaje',
        'leida',          // 'N' = no leida, 'S' = leida
        'fecha_hora',
        'usuarios_id',
        'anomalias_id',
    ];

    protected $casts = [
        'fecha_hora' => 'datetime',
    ];

    // La tabla notificaciones no tiene created_at/updated_at.
    public $timestamps = false;

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuarios_id');
    }

    public function scopeNoLeidas($query)
    {
        return $query->where('leida', self::NO_LEIDA);
    }
}