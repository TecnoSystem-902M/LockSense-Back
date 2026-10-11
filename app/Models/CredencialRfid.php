<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CredencialRfid extends Model
{
    protected $table = 'credenciales_rfid';

    protected $fillable = [
        'usuarios_id',
        'codigo',
        'tipo',
        'estado',
        'fecha_registro',
    ];

    protected $casts = [
        'fecha_registro' => 'datetime',
    ];

    // El esquema real no tiene created_at/updated_at.
    public $timestamps = false;

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuarios_id');
    }
}