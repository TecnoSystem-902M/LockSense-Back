<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionCodigo extends Model
{
    protected $table = 'verificacion_codigos';
    public $timestamps = false; // Tu tabla no tiene created_at/updated_at

    protected $fillable = [
        'usuarios_id',
        'codigo',
        'tipo',
        'fecha_expiracion',
        'utilizado',
    ];

    protected $casts = [
        'fecha_expiracion' => 'datetime',
        'utilizado'        => 'boolean',
    ];
}