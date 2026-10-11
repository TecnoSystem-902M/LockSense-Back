<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Casillero extends Model
{
    protected $table = 'casilleros';

    // Motivos de bloqueo permitidos (Etapa 2).
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
        'bloqueado',
        'motivo_bloqueo',
    ];

    protected $casts = [
        'bloqueado'  => 'boolean',
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

    /**
     * Asignación activa del casillero (estado = true), si existe.
     */
    public function asignacionActiva()
    {
        return $this->hasOne(Asignacion::class, 'casilleros_id')
            ->where('estado', true)
            ->latest('id');
    }

    public function eventos()
    {
        return $this->hasMany(EventoCasillero::class, 'casilleros_id');
    }

    /**
     * Ocupación derivada: el casillero está ocupado si tiene una asignación
     * activa (estado = true). No depende del bloqueo ni del campo `estado`.
     * Nota: requiere que `asignacionActiva` esté cargada o accesible.
     */
    public function estaOcupado(): bool
    {
        return $this->asignacionActiva()->exists();
    }
}
