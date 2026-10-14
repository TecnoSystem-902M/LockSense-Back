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

    public function estaOcupado(): bool
    {
        return $this->asignacionActiva()->exists();
    }

    public function estaBloqueado(): bool
    {
        return $this->bloqueoActivo()->exists();
    }
}