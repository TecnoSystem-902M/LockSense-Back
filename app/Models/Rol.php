<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    protected $table = 'roles';
    public $timestamps = false;

    // Constantes para evitar números mágicos en el código
    const SUPERADMIN    = 1;
    const ADMINISTRADOR = 2;
    const USUARIO       = 3;

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'roles_id');
    }
}