<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dispositivo (celular o computadora) donde un usuario aceptó recibir
 * alertas de anomalías. Un usuario puede tener varios.
 */
class PushSuscripcion extends Model
{
    protected $table = 'push_suscripciones';

    protected $fillable = [
        'usuarios_id',
        'endpoint',
        'p256dh',
        'auth',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuarios_id');
    }
}