<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente del servicio de reconocimiento facial (Python / FastAPI).
 *
 *   obtenerEmbedding(): manda la foto a POST /v1/embed y devuelve el vector.
 *   similitud():        compara dos vectores (similitud coseno, -1 a 1).
 *   detectarObjetos():  manda la foto a POST /v1/detect (YOLO, HU7).
 *
 * Usa la misma configuracion que el registro facial:
 *   services.face.url, services.face.key, services.face.timeout
 *
 * Nunca registra en logs la imagen, el embedding ni la API key.
 */
class ClienteFacial
{
    private const DIMENSION_EMBEDDING = 512;

    /**
     * Exito: ['ok' => true, 'embedding' => [512 floats], 'modelo' => 'buffalo_l']
     * Error: ['ok' => false, 'code' => '...']
     *
     * Codigos de error:
     *   servicio_no_configurado, servicio_no_disponible, respuesta_invalida
     *     -> problema del servidor, no de la foto
     *   no_se_detecto_rostro, mas_de_un_rostro, calidad_insuficiente, imagen_invalida
     *     -> problema de la foto
     */
    public function obtenerEmbedding(UploadedFile $imagen): array
    {
        $url = config('services.face.url');
        $key = config('services.face.key');

        if (empty($url) || empty($key)) {
            Log::warning('Acceso facial: servicio de embeddings no configurado.');

            return ['ok' => false, 'code' => 'servicio_no_configurado'];
        }

        try {
            $respuesta = Http::timeout((int) config('services.face.timeout', 15))
                ->withHeaders(['X-API-Key' => $key])
                ->attach(
                    'image',
                    file_get_contents($imagen->getRealPath()),
                    $imagen->getClientOriginalName() ?: 'captura.jpg'
                )
                ->post(rtrim($url, '/') . '/v1/embed');
        } catch (Throwable $e) {
            Log::error('Acceso facial: fallo al contactar el servicio de embeddings.', [
                'exception' => get_class($e),
            ]);

            return ['ok' => false, 'code' => 'servicio_no_disponible'];
        }

        if (! $respuesta->successful()) {
            $cuerpo = $respuesta->json();
            $code = is_array($cuerpo) ? ($cuerpo['code'] ?? null) : null;

            // Problemas entre servicios (clave, modelo no cargado...).
            if (in_array($respuesta->status(), [401, 403, 503], true)
                || in_array($code, ['unauthorized', 'unavailable'], true)) {
                Log::error('Acceso facial: el servicio de embeddings rechazo la solicitud.', [
                    'status' => $respuesta->status(),
                ]);

                return ['ok' => false, 'code' => 'servicio_no_disponible'];
            }

            return [
                'ok'   => false,
                'code' => match ($code) {
                    'no_face'        => 'no_se_detecto_rostro',
                    'multiple_faces' => 'mas_de_un_rostro',
                    'low_quality'    => 'calidad_insuficiente',
                    default          => 'imagen_invalida',
                },
            ];
        }

        $datos = $respuesta->json();

        if (! $this->embeddingValido($datos)) {
            Log::warning('Acceso facial: respuesta del servicio no cumple el contrato.');

            return ['ok' => false, 'code' => 'respuesta_invalida'];
        }

        return [
            'ok'        => true,
            'embedding' => array_map('floatval', $datos['embedding']),
            'modelo'    => (string) ($datos['modelo'] ?? 'desconocido'),
        ];
    }

    /**
     * Similitud coseno entre dos vectores: 1 = identicos, 0 = nada que ver.
     * Devuelve -1 si los vectores no son comparables.
     */
    public function similitud(array $a, array $b): float
    {
        if (count($a) !== count($b) || count($a) === 0) {
            return -1.0;
        }

        $producto = 0.0;
        $normaA = 0.0;
        $normaB = 0.0;

        foreach ($a as $i => $valorA) {
            $valorB = (float) $b[$i];
            $valorA = (float) $valorA;

            $producto += $valorA * $valorB;
            $normaA   += $valorA * $valorA;
            $normaB   += $valorB * $valorB;
        }

        if ($normaA <= 0.0 || $normaB <= 0.0) {
            return -1.0;
        }

        return $producto / (sqrt($normaA) * sqrt($normaB));
    }

    /**
     * HU7: detecta personas y objetos en una foto con YOLO.
     *
     * Exito: ['ok' => true, 'personas' => 2, 'confianza_personas' => 0.87,
     *         'sospechosos' => [['clase' => 'knife', 'nombre' => 'cuchillo', 'confianza' => 0.61]],
     *         'modelo' => 'yolov8n']
     * Error: ['ok' => false, 'code' => '...']
     */
    public function detectarObjetos(string $bytes, string $nombreArchivo = 'evidencia.jpg'): array
    {
        $url = config('services.face.url');
        $key = config('services.face.key');

        if (empty($url) || empty($key)) {
            return ['ok' => false, 'code' => 'servicio_no_configurado'];
        }

        try {
            $respuesta = Http::timeout((int) config('services.face.timeout', 15))
                ->withHeaders(['X-API-Key' => $key])
                ->attach('image', $bytes, $nombreArchivo)
                ->post(rtrim($url, '/') . '/v1/detect');
        } catch (Throwable $e) {
            Log::error('YOLO: fallo al contactar el servicio.', ['exception' => get_class($e)]);

            return ['ok' => false, 'code' => 'servicio_no_disponible'];
        }

        $datos = $respuesta->json();

        if (! $respuesta->successful() || ! is_array($datos) || ($datos['ok'] ?? null) !== true) {
            Log::warning('YOLO: el servicio no pudo analizar la foto.', ['status' => $respuesta->status()]);

            return ['ok' => false, 'code' => is_array($datos) ? ($datos['code'] ?? 'error') : 'error'];
        }

        return [
            'ok'                 => true,
            'personas'           => (int) ($datos['personas'] ?? 0),
            'confianza_personas' => (float) ($datos['confianza_personas'] ?? 0),
            'sospechosos'        => is_array($datos['sospechosos'] ?? null) ? $datos['sospechosos'] : [],
            'modelo'             => (string) ($datos['modelo'] ?? 'yolo'),
        ];
    }

    private function embeddingValido(?array $datos): bool
    {
        if (! is_array($datos) || ($datos['ok'] ?? null) !== true) {
            return false;
        }

        $embedding = $datos['embedding'] ?? null;

        if (! is_array($embedding) || count($embedding) !== self::DIMENSION_EMBEDDING) {
            return false;
        }

        foreach ($embedding as $valor) {
            if ((! is_int($valor) && ! is_float($valor)) || ! is_finite($valor)) {
                return false;
            }
        }

        return true;
    }
}