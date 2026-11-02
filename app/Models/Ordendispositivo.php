<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Orden que Laravel deja para un ESP32 (ej. "abrir la posicion 1").
 * El ESP32 la recoge con GET /api/dispositivo/ordenes.
 */
class OrdenDispositivo extends Model
{
    protected $table = 'ordenes_dispositivo';

    const TIPO_ABRIR = 'ABRIR';

    const PENDIENTE = 'P';
    const ENTREGADA = 'E';
    const EXPIRADA  = 'X';

    protected $fillable = [
        'tipo',
        'posicion',
        'estado',
        'expira_en',
        'entregada_en',
        'dispositivos_id',
        'casilleros_id',
        'accesos_id',
    ];

    protected $casts = [
        'posicion'     => 'integer',
        'expira_en'    => 'datetime',
        'entregada_en' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function dispositivo()
    {
        return $this->belongsTo(Dispositivo::class, 'dispositivos_id');
    }

    public function casillero()
    {
        return $this->belongsTo(Casillero::class, 'casilleros_id');
    }

    public function acceso()
    {
        return $this->belongsTo(Acceso::class, 'accesos_id');
    }
}