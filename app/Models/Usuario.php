<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'identificador',
        'nombre',
        'apellido_pa',
        'apellido_ma',
        'email',
        'password',
        'telefono',
        'estado',
        'organizaciones_id',
        'roles_id',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function organizacion()
    {
        return $this->belongsTo(Organizacion::class, 'organizaciones_id');
    }

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'roles_id');
    }

    /**
     * Rostros del usuario. La tabla `rostros` NO tiene UNIQUE sobre
     * `usuarios_id`, por lo que puede haber más de un registro; se modela
     * como hasMany para no asumir unicidad.
     */
    public function rostros()
    {
        return $this->hasMany(Rostro::class, 'usuarios_id');
    }

    /**
     * Rostro activo (estado = 'A'), si existe. Se usa para calcular el
     * indicador `tiene_rostro` sin considerar registros inactivos.
     */
    public function rostroActivo()
    {
        return $this->hasOne(Rostro::class, 'usuarios_id')
            ->where('estado', 'A')
            ->latest('id');
    }
}