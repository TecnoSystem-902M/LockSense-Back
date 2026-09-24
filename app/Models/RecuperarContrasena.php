<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecuperarContrasena extends Model
{
    protected $table = 'recuperar_contrasena';
    public $timestamps = false;

    protected $fillable = [
        'email',
        'codigo',
        'expira_en',
        'usado',
        'usuarios_id',
    ];

    protected $casts = [
        'expira_en' => 'datetime',
    ];
}