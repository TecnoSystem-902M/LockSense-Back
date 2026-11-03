<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Foto de evidencia de una anomalia (HU7), guardada en la base de datos.
 * La imagen va en base64; se borra sola despues de DIAS_RETENCION dias.
 */
class Evidencia extends Model
{
    protected $table = 'evidencias';

    // Solo tiene created_at.
    public $timestamps = false;

    const DIAS_RETENCION = 30;

    protected $fillable = [
        'imagen_base64',
        'mime',
        'tamano_bytes',
        'created_at',
        'anomalias_id',
    ];

    // Nunca mandar la imagen completa en listados JSON.
    protected $hidden = ['imagen_base64'];

    protected $casts = [
        'created_at'   => 'datetime',
        'tamano_bytes' => 'integer',
    ];

    public function anomalia()
    {
        return $this->belongsTo(Anomalia::class, 'anomalias_id');
    }

    /** Borra las evidencias mas viejas que DIAS_RETENCION. */
    public static function borrarVencidas(): int
    {
        return static::where('created_at', '<', now()->subDays(self::DIAS_RETENCION))->delete();
    }
}