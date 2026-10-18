<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Casillero extends Model
{
    protected $table = 'casilleros';

    const MOTIVO_ADMINISTRATIVO = 'ADMINISTRATIVO';
    const MOTIVO_MANTENIMIENTO  = 'MANTENIMIENTO';

    const MOTIVOS = [
        self::MOTIVO_ADMINISTRATIVO,
        self::MOTIVO_MANTENIMIENTO,
    ];

    protected $fillable = [
        'organizaciones_id',
        'dispositivos_id',
        'numero',
        'estado',
        'ubicacion',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function organizacion()
    {
        return $this->belongsTo(Organizacion::class, 'organizaciones_id');
    }

    public function dispositivo()
    {
        return $this->belongsTo(Dispositivo::class, 'dispositivos_id');
    }

    public function asignaciones()
    {
        return $this->hasMany(Asignacion::class, 'casilleros_id');
    }

    public function asignacionActiva()
    {
        return $this->hasOne(Asignacion::class, 'casilleros_id')
            ->where('estado', 'A')
            ->latest('id');
    }

    /**
     * Bloqueo activo del casillero (activo = 'A'), si existe.
     */
    public function bloqueoActivo()
    {
        return $this->hasOne(Bloqueo::class, 'casilleros_id')
            ->where('activo', 'A')
            ->latest('id');
    }

    public function bloqueos()
    {
        return $this->hasMany(Bloqueo::class, 'casilleros_id');
    }

    public function eventos()
    {
        return $this->hasMany(EventoCasillero::class, 'casilleros_id');
    }

    public function accesos()
    {
        return $this->hasMany(Acceso::class, 'casilleros_id');
    }

    public function estaOcupado(): bool
    {
        return $this->asignacionActiva()->exists();
    }

    public function estaBloqueado(): bool
    {
        return $this->bloqueoActivo()->exists();
    }

    /**
     * Posicion fisica de la cerradura en el ESP32.
     * "A-03" -> 3
     */
    public function getPosicionAttribute(): int
    {
        return (int) substr($this->numero, -2);
    }
}