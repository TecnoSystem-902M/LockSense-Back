<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionCodigo extends Model
{
    protected $table = 'verificacion_codigos';
    public $timestamps = false; // Tu tabla no tiene created_at/updated_at

    protected $fillable = [
        'email',
        'codigo',
        'tipo',
        'expira_en',
        'usado',
        'usuarios_id',
    ];

    protected $casts = [
        'expira_en' => 'datetime',
    ];
}