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
        'apellido_paterno',
        'apellido_materno',
        'correo',
        'contrasena',
        'telefono',
        'estado',
        'organizaciones_id',
        'roles_id',
    ];

    protected $hidden = ['contrasena'];

    protected $casts = [
        'estado'     => 'boolean',
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
}