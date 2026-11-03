<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Anomalia extends Model
{
    protected $table = 'anomalias';

    // La tabla anomalias no tiene created_at/updated_at (usa fecha_hora).
    public $timestamps = false;

    // ---------- Estado ----------
    const PENDIENTE  = 'P';
    const REVISADA   = 'R';
    const DESCARTADA = 'D';

    const ESTADOS = [self::PENDIENTE, self::REVISADA, self::DESCARTADA];

    // ---------- Origen: que la detecto ----------
    const ORIGEN_REGLA       = 'REGLA';        // sensores y reglas del sistema
    const ORIGEN_IA_IMAGEN   = 'IA_IMAGEN';    // YOLO sobre la foto de la camara
    const ORIGEN_IA_PATRONES = 'IA_PATRONES';  // Isolation Forest sobre los accesos

    // ---------- Tipos ----------
    const TIPO_APERTURA_NO_AUTORIZADA = 'APERTURA_NO_AUTORIZADA';
    const TIPO_INTENTOS_FALLIDOS      = 'INTENTOS_FALLIDOS';
    // Etapas siguientes:
    const TIPO_VIBRACION              = 'VIBRACION';
    const TIPO_PUERTA_ABIERTA_PROLONGADA = 'PUERTA_ABIERTA_PROLONGADA';
    const TIPO_PERSONAS_MULTIPLES     = 'PERSONAS_MULTIPLES';
    const TIPO_OBJETO_SOSPECHOSO      = 'OBJETO_SOSPECHOSO';
    const TIPO_PATRON_INUSUAL         = 'PATRON_INUSUAL';

    // ---------- Nivel de riesgo ----------
    const RIESGO_BAJO    = 'BAJO';
    const RIESGO_MEDIO   = 'MEDIO';
    const RIESGO_ALTO    = 'ALTO';
    const RIESGO_CRITICO = 'CRITICO';

    protected $fillable = [
        'tipo',
        'descripcion',
        'nivel_riesgo',
        'confianza',          // 0 a 1 (1 = regla segura; la IA da un valor menor)
        'estado',
        'evidencia_url',      // foto de la camara (etapa 3)
        'origen',
        'fecha_hora',
        'organizaciones_id',
        'casilleros_id',
        'dispositivos_id',
        'accesos_id',
        'revisada_por',
        'fecha_revision',
        'usuarios_id',        // dueno del casillero cuando ocurrio
    ];

    protected $casts = [
        'confianza'      => 'float',
        'fecha_hora'     => 'datetime',
        'fecha_revision' => 'datetime',
    ];

    public function casillero()
    {
        return $this->belongsTo(Casillero::class, 'casilleros_id');
    }

    public function dispositivo()
    {
        return $this->belongsTo(Dispositivo::class, 'dispositivos_id');
    }

    public function acceso()
    {
        return $this->belongsTo(Acceso::class, 'accesos_id');
    }

    public function organizacion()
    {
        return $this->belongsTo(Organizacion::class, 'organizaciones_id');
    }

    /** Dueno del casillero cuando ocurrio: recibe la alerta y ve la foto. */
    public function dueno()
    {
        return $this->belongsTo(Usuario::class, 'usuarios_id');
    }

    public function revisor()
    {
        return $this->belongsTo(Usuario::class, 'revisada_por');
    }

    public function notificaciones()
    {
        return $this->hasMany(Notificacion::class, 'anomalias_id');
    }

    public function evidencias()
    {
        return $this->hasMany(Evidencia::class, 'anomalias_id');
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', self::PENDIENTE);
    }
}