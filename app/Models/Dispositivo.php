<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dispositivo extends Model
{
    protected $table = 'dispositivos';
    const TIPO_CERRADURAS = 'CERRADURAS';
    const TIPO_CAMARA     = 'CAMARA';

    protected $fillable = [
        'tipo', 
        'identificador',      // MAC del ESP32
        'token',              // hash SHA-256 del token
        'estado',             // 'A' = activo, 'I' = inactivo
        'ultima_conexion',
        'organizaciones_id',
    ];

    // Nunca exponer el hash del token en respuestas JSON.
    protected $hidden = ['token'];

    protected $casts = [
        'ultima_conexion' => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function organizacion()
    {
        return $this->belongsTo(Organizacion::class, 'organizaciones_id');
    }

    public function casilleros()
    {
        return $this->hasMany(Casillero::class, 'dispositivos_id');
    }

    public function accesos()
    {
        return $this->hasMany(Acceso::class, 'dispositivos_id');
    }

    public function eventos()
    {
        return $this->hasMany(EventoCasillero::class, 'dispositivos_id');
    }

    /** Compara un token en texto plano contra el hash guardado. */
    public function tokenEsValido(string $token): bool
    {
        return hash_equals($this->token, hash('sha256', $token));
    }

    public function estaActivo(): bool
    {
        return $this->estado === 'A';
    }

    public function esCamara(): bool
    {
        return $this->tipo === self::TIPO_CAMARA;
    }
 
    /** Camaras activas (para pedirles fotos de evidencia). */
    public function scopeCamarasActivas($query, int $organizacionId)
    {
        return $query->where('organizaciones_id', $organizacionId)
            ->where('tipo', self::TIPO_CAMARA)
            ->where('estado', 'A');
    }
}