<?php

namespace App\Http\Controllers;

use App\Models\Rostro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Registro de rostro (HU10) — parte inicial del backend.
 *
 * Flujo:
 *   1. Recibe una imagen (multipart/form-data, campo `imagen`) del usuario
 *      autenticado por Sanctum.
 *   2. La reenvía al servicio externo de embeddings (Python/FastAPI) con la
 *      API key compartida en el header X-API-Key.
 *   3. Verifica que la respuesta cumpla el contrato (ok=true, embedding de
 *      512 números, nombre del modelo).
 *   4. Solo si el embedding es válido, lo persiste en `rostros` asociado al
 *      usuario autenticado, con estado activo 'A'.
 *
 * Nunca registra en logs la imagen, el embedding ni la API key. Nunca acepta
 * `usuarios_id` desde el cuerpo: usa el usuario autenticado.
 *
 * Decisiones PENDIENTES del equipo (ver informe):
 *   - Tamaño máximo de imagen y criterios de calidad biométrica.
 *   - Política de reemplazo cuando ya existe un rostro activo (hoy se rechaza
 *     con 409 para no crear duplicados ni borrar registros existentes).
 */
class RostroController extends Controller
{
    // Dimensión esperada del embedding (InsightFace: 512).
    private const DIMENSION_EMBEDDING = 512;

    public function store(Request $request)
    {
        $usuario = $request->user();

        // 1) Validación básica del archivo. El límite de tamaño (max) es
        //    PROVISIONAL: no hay valor documentado en el proyecto (ver informe).
        $validator = Validator::make($request->all(), [
            'imagen' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png',
                // 8192 KB = 8 MB. Valor provisional, pendiente de decisión.
                'max:8192',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'code'    => 'imagen_invalida',
                'message' => 'La imagen no es válida.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 2) Política de múltiples rostros: si ya hay uno activo, NO duplicamos
        //    ni reemplazamos (no se autorizó política de reemplazo). Se rechaza
        //    con un error controlado; el equipo decidirá la política definitiva.
        if ($usuario->rostroActivo()->exists()) {
            return response()->json([
                'ok'      => false,
                'code'    => 'rostro_ya_registrado',
                'message' => 'Ya tienes un rostro registrado.',
            ], 409);
        }

        // 3) Configuración del servicio Python. Si falta, error controlado.
        $url = config('services.face.url');
        $key = config('services.face.key');

        if (empty($url) || empty($key)) {
            Log::warning('Registro facial: servicio de embeddings no configurado.');

            return response()->json([
                'ok'      => false,
                'code'    => 'servicio_no_configurado',
                'message' => 'El servicio de reconocimiento facial no está disponible.',
            ], 503);
        }

        // 4) Llamada al servicio Python con la imagen y la API key.
        try {
            $imagen = $request->file('imagen');

            $respuesta = Http::timeout((int) config('services.face.timeout', 15))
                ->withHeaders(['X-API-Key' => $key])
                ->attach(
                    'image',
                    file_get_contents($imagen->getRealPath()),
                    $imagen->getClientOriginalName()
                )
                ->post(rtrim($url, '/') . '/v1/embed');
        } catch (Throwable $e) {
            // No se expone el detalle interno; solo se registra la clase.
            Log::error('Registro facial: fallo al contactar el servicio de embeddings.', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'ok'      => false,
                'code'    => 'servicio_no_disponible',
                'message' => 'No se pudo contactar el servicio de reconocimiento facial.',
            ], 503);
        }

        // 5) El servicio respondió, pero con error HTTP.
        if (! $respuesta->successful()) {
            $cuerpo = $respuesta->json();
            $codeServicio = is_array($cuerpo) ? ($cuerpo['code'] ?? null) : null;

            // Rechazo de autenticación/configuración ENTRE SERVICIOS (Laravel
            // <-> Python). No es culpa de la imagen del usuario ni de su sesión:
            // es un problema de infraestructura, así que se comunica como 503 y
            // NUNCA como 401 al frontend (un 401 cerraría la sesión del usuario).
            if (
                $respuesta->status() === 401
                || $respuesta->status() === 403
                || $codeServicio === 'unauthorized'
            ) {
                // Log interno para diagnóstico; sin clave, cuerpo ni trazas.
                Log::error('Registro facial: autenticación con el servicio de embeddings rechazada.', [
                    'status' => $respuesta->status(),
                ]);

                return response()->json([
                    'ok'      => false,
                    'code'    => 'servicio_no_disponible',
                    'message' => 'El servicio de reconocimiento facial no está disponible.',
                ], 503);
            }

            // Resto de errores: se tratan como problemas de procesamiento de
            // la imagen (no_face, multiple_faces, invalid_image, low_quality...).
            return response()->json($this->mapearErrorServicio($cuerpo), 422);
        }

        $datos = $respuesta->json();

        // 6) Verificación del contrato antes de persistir.
        if (! $this->embeddingValido($datos)) {
            Log::warning('Registro facial: respuesta del servicio no cumple el contrato.');

            return response()->json([
                'ok'      => false,
                'code'    => 'respuesta_invalida',
                'message' => 'El servicio devolvió una respuesta inválida.',
            ], 422);
        }

        // 7) Persistencia: solo con embedding real y válido. estado activo 'A'.
        try {
            $rostro = Rostro::query()->create([
                'embedding'   => json_encode($datos['embedding']),
                'modelo'      => (string) ($datos['modelo'] ?? 'desconocido'),
                'estado'      => 'A',
                'usuarios_id' => $usuario->id,
            ]);
        } catch (Throwable $e) {
            Log::error('Registro facial: fallo al guardar el rostro.', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'ok'      => false,
                'code'    => 'error_guardado',
                'message' => 'No se pudo guardar tu rostro. Intenta de nuevo.',
            ], 500);
        }

        return response()->json([
            'ok'           => true,
            'message'      => 'Rostro registrado correctamente.',
            'tiene_rostro' => true,
            'rostro_id'    => $rostro->id,
        ], 201);
    }

    /**
     * Comprueba que la respuesta cumpla el contrato:
     * ok=true, embedding = arreglo de exactamente 512 números.
     */
    private function embeddingValido(?array $datos): bool
    {
        if (! is_array($datos)) {
            return false;
        }

        if (($datos['ok'] ?? null) !== true) {
            return false;
        }

        $embedding = $datos['embedding'] ?? null;

        if (! is_array($embedding) || count($embedding) !== self::DIMENSION_EMBEDDING) {
            return false;
        }

        foreach ($embedding as $valor) {
            // Debe ser numérico (int/float) y además finito: se rechazan
            // explícitamente NaN, INF y -INF (is_finite() devuelve false para
            // esos valores y true solo para números reales acotados). No se
            // aceptan cadenas ni se convierten silenciosamente a número.
            if (! is_int($valor) && ! is_float($valor)) {
                return false;
            }

            if (! is_finite($valor)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Traduce los códigos de error conocidos del servicio Python a una
     * respuesta controlada, sin exponer detalles internos.
     */
    private function mapearErrorServicio(?array $datos): array
    {
        $code = is_array($datos) ? ($datos['code'] ?? null) : null;

        $mensajes = [
            'invalid_image'    => 'La imagen no es válida.',
            'no_face'          => 'No detectamos un rostro en la imagen.',
            'multiple_faces'   => 'La imagen contiene más de un rostro.',
            'low_quality'      => 'La calidad de la imagen es insuficiente.',
        ];

        $codigo = match ($code) {
            'invalid_image'  => 'imagen_invalida',
            'no_face'        => 'no_se_detecto_rostro',
            'multiple_faces' => 'mas_de_un_rostro',
            'low_quality'    => 'calidad_insuficiente',
            default          => 'procesamiento_fallido',
        };

        return [
            'ok'      => false,
            'code'    => $codigo,
            'message' => $mensajes[$code] ?? 'No se pudo procesar la imagen.',
        ];
    }
}
