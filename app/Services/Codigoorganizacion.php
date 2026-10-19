<?php

namespace App\Services;

use App\Models\Organizacion;
use Illuminate\Support\Str;

/**
 * Código corto de cada organización (ej. "UTN") y prefijo de las matrículas.
 *
 *   Matrícula que escribe el admin:   A001
 *   Identificador guardado (login):   UTN-A001
 *
 * Como el código es único por organización, que `usuarios.identificador`
 * sea único en toda la plataforma garantiza también que la matrícula no
 * se repita dentro de la misma organización.
 */
class CodigoOrganizacion
{
    private const SEPARADOR = '-';
    private const LONGITUD_MAXIMA = 6;

    // Palabras que no aportan iniciales.
    private const PALABRAS_IGNORADAS = [
        'DE', 'DEL', 'LA', 'LAS', 'EL', 'LOS', 'Y', 'E', 'EN', 'A', 'AL',
        'SA', 'CV', 'S', 'C', 'V', 'SC', 'AC',
    ];

    /**
     * Devuelve el código de la organización; si no tiene, lo genera y lo guarda.
     */
    public static function asegurar(Organizacion $organizacion): string
    {
        if (! empty($organizacion->codigo)) {
            return $organizacion->codigo;
        }

        $codigo = self::generarUnico($organizacion->nombre, $organizacion->id);

        $organizacion->forceFill(['codigo' => $codigo])->save();

        return $codigo;
    }

    /**
     * "Universidad Tecnológica de Nezahualcóyotl" -> "UTN"
     * Si ya existe otra organización con ese código: "UTN2", "UTN3", ...
     */
    public static function generarUnico(string $nombre, ?int $ignorarId = null): string
    {
        $base = self::generarBase($nombre);
        $codigo = $base;
        $n = 2;

        while (self::codigoEnUso($codigo, $ignorarId)) {
            $sufijo = (string) $n++;
            $codigo = substr($base, 0, self::LONGITUD_MAXIMA - strlen($sufijo)) . $sufijo;
        }

        return $codigo;
    }

    /** Arma el identificador de acceso: ("UTN", "a001") -> "UTN-A001" */
    public static function componer(string $codigo, string $matricula): string
    {
        $matricula = self::normalizarMatricula($matricula);

        // Si el admin ya escribió el prefijo, no duplicarlo.
        $prefijo = $codigo . self::SEPARADOR;
        if (str_starts_with($matricula, $prefijo)) {
            $matricula = substr($matricula, strlen($prefijo));
        }

        return $prefijo . $matricula;
    }

    /** Quita el prefijo para mostrar la matrícula: ("UTN", "UTN-A001") -> "A001" */
    public static function matricula(?string $codigo, ?string $identificador): ?string
    {
        if ($identificador === null) {
            return null;
        }

        $prefijo = ($codigo ?? '') . self::SEPARADOR;

        if ($codigo && str_starts_with($identificador, $prefijo)) {
            return substr($identificador, strlen($prefijo));
        }

        return $identificador;
    }

    /** Mayúsculas, sin espacios ni caracteres raros. */
    public static function normalizarMatricula(string $matricula): string
    {
        $matricula = strtoupper(Str::ascii(trim($matricula)));

        return preg_replace('/[^A-Z0-9_\-\.]/', '', $matricula);
    }

    // --------------------------------------------------

    private static function generarBase(string $nombre): string
    {
        $limpio = strtoupper(Str::ascii($nombre));
        $palabras = preg_split('/[^A-Z0-9]+/', $limpio, -1, PREG_SPLIT_NO_EMPTY);

        $significativas = array_values(array_filter(
            $palabras,
            fn ($p) => ! in_array($p, self::PALABRAS_IGNORADAS, true)
        ));

        if (count($significativas) >= 2) {
            // Iniciales: "UNIVERSIDAD TECNOLOGICA NEZAHUALCOYOTL" -> "UTN"
            $base = implode('', array_map(fn ($p) => $p[0], $significativas));
        } elseif (count($significativas) === 1) {
            // Una sola palabra: "SMARTFIT" -> "SMA"
            $base = substr($significativas[0], 0, 3);
        } else {
            $base = 'ORG';
        }

        $base = substr($base, 0, self::LONGITUD_MAXIMA);

        // Mínimo 2 letras para que se lea como código.
        return strlen($base) >= 2 ? $base : str_pad($base, 2, 'X');
    }

    private static function codigoEnUso(string $codigo, ?int $ignorarId): bool
    {
        return Organizacion::query()
            ->where('codigo', $codigo)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();
    }
}