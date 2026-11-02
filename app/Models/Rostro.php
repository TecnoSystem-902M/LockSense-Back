<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rostro extends Model
{
    protected $table = 'rostros';

    /**
     * Columnas rellenables, con los nombres exactos del esquema `rostros`
     * del backup del equipo:
     *   embedding (text) · modelo (varchar 100) · estado (char 1) · usuarios_id (fk)
     *
     * `estado` se maneja como texto ('A' activo / 'I' inactivo), NO booleano.
     */
    protected $fillable = [
        'embedding',
        'modelo',
        'estado',
        'usuarios_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuarios_id');
    }
}
