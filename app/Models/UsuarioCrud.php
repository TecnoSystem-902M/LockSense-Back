<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo dedicado EXCLUSIVAMENTE al CRUD administrativo de usuarios.
 *
 * Apunta a la tabla real `usuarios` usando los nombres de columna reales de
 * PostgreSQL. NO extiende Authenticatable ni participa en el flujo de login
 * (ese sigue siendo responsabilidad de App\Models\Usuario, que no se toca).
 */
class UsuarioCrud extends Model
{
    protected $table = 'usuarios';

    protected $fillable = [
        'organizaciones_id',
        'roles_id',
        'identificador',
        'nombre',
        'apellido_pa',
        'apellido_ma',
        'email',
        'password',
        'telefono',
        'estado',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'estado' => 'boolean',
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
